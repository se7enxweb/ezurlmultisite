<?php
/**
 * File containing the eZURLMultisite class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2 (or any later version)
 * @package ezurlmultisite
 */

/**
 * Cross-siteaccess link resolution for the ezurl template operator.
 *
 * In a multisite installation every site has its own subtree, and every
 * siteaccess hides the path of its own subtree with PathPrefix. A node of
 * another site keeps that site's path in its URL alias (fit-healthy/video/...
 * seen from Bold Agency), and the plain ezurl operator puts the current
 * siteaccess in front of it: /bold/fit-healthy/video/..., which does not
 * exist. eZURLMultisite finds the siteaccess the content belongs to and
 * builds the address on that siteaccess instead (/video/... or
 * https://other.host/video/...).
 *
 * Which siteaccesses take part, and which subtree belongs to each, is read
 * from ezurlmultisite.ini [MultisiteSettings]; without it, every public
 * siteaccess with a PathPrefix takes part and its root node comes from its
 * content.ini RootNode or its IndexPage.
 */
class eZURLMultisite
{
    /** Per process: built from the INI files only. */
    private static $siteAccessData = null;
    private static $participants = null;
    private static $ini = null;
    private static $pathMap = null;

    /** Per request: reset by beginRequest(). */
    private static $requestKey = null;
    private static $nodeCache = array();
    private static $targetCache = array();
    private static $emptyPrefixSiteAccess = null;
    private static $currentNodeID = null;

    /**
     * Returns the ezurlmultisite INI instance.
     */
    public static function ini()
    {
        if ( self::$ini === null )
        {
            $ini = eZINI::instance( 'ezurlmultisite.ini' );
            if ( !$ini->hasVariable( 'OperatorSettings', 'ForceAbsoluteURL' ) )
            {
                $ini = eZINI::instance( 'ezurlmultisite.ini', 'extension/ezurlmultisite/settings', null, null, true, true );
            }
            self::$ini = $ini;
        }
        return self::$ini;
    }

    private static $active = null;

    /**
     * Whether the extension is active in this installation. Other extensions
     * that build content links themselves (instead of with ezurl) ask this
     * before calling crossSiteURL().
     */
    public static function isActive()
    {
        if ( self::$active === null )
        {
            self::$active = in_array( 'ezurlmultisite', eZExtension::activeExtensions(), true );
        }
        return self::$active;
    }

    /**
     * Resets every static cache. Useful in long-running processes and tests.
     */
    public static function resetCache()
    {
        self::$active = null;
        self::$siteAccessData = null;
        self::$participants = null;
        self::$ini = null;
        self::$pathMap = null;
        self::$requestKey = null;
        self::resetRequestCache();
    }

    /**
     * Drops the caches that belong to one request. An application server that
     * keeps its PHP workers between requests (Velocity) would otherwise carry
     * the current node and the URL alias lookups of one request into the next.
     */
    public static function beginRequest()
    {
        $key = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (string)$_SERVER['REQUEST_TIME_FLOAT'] : '';
        $key .= '|' . self::currentSiteAccessName();
        if ( self::$requestKey !== $key )
        {
            self::$requestKey = $key;
            self::resetRequestCache();
        }
    }

    private static function resetRequestCache()
    {
        self::$nodeCache = array();
        self::$targetCache = array();
        self::$emptyPrefixSiteAccess = null;
        self::$currentNodeID = null;
    }

    /**
     * The address of $value on the siteaccess it belongs to, when that is not
     * the current one; false when the link stays on the current siteaccess
     * and the kernel ezurl behaviour applies unchanged.
     *
     * @param mixed $value URL alias / path string, node, object or node id
     * @param string $serverURL 'relative' or 'full', as the ezurl parameter
     * @param string $forceSiteAccess target siteaccess to use instead of the detected one
     * @return string|false
     */
    public static function crossSiteURL( $value, $serverURL = 'relative', $forceSiteAccess = '' )
    {
        self::beginRequest();

        $current = self::currentSiteAccessName();
        if ( $forceSiteAccess === '' && !self::isParticipant( $current ) )
        {
            return false;
        }

        $node = false;
        $suffix = '';
        if ( $value instanceof eZContentObjectTreeNode )
        {
            $node = $value;
            $path = (string)$node->attribute( 'url_alias' );
        }
        elseif ( $value instanceof eZContentObject )
        {
            $node = $value->mainNode();
            if ( !$node )
            {
                return false;
            }
            $path = (string)$node->attribute( 'url_alias' );
        }
        elseif ( is_string( $value ) )
        {
            $path = trim( $value );
            if ( $path === '' || preg_match( '#^([a-zA-Z][a-zA-Z0-9+.-]*:|//|\#|\?)#', $path ) )
            {
                return false;
            }
            if ( preg_match( '#^([^?\#]*)([?\#].*)$#s', $path, $matches ) )
            {
                $path = $matches[1];
                $suffix = $matches[2];
            }
        }
        else
        {
            return false;
        }

        $path = trim( $path, '/' );

        if ( $forceSiteAccess !== '' )
        {
            $target = $forceSiteAccess;
        }
        elseif ( $node instanceof eZContentObjectTreeNode )
        {
            $target = self::targetForNode( $node, $path );
        }
        else
        {
            $target = self::targetForPath( $path );
        }

        if ( $target === false || $target === '' || $target === $current )
        {
            return false;
        }

        $relative = self::stripPrefix( $path, self::pathPrefixForSiteAccess( $target ) );
        return self::siteAccessURL( $target, $relative, $serverURL ) . $suffix;
    }

    /**
     * The participating siteaccess a path belongs to, by PathPrefix: the
     * longest prefix the path starts with wins. False when the path starts
     * with the current siteaccess' own prefix or with none.
     */
    public static function targetForPath( $path )
    {
        $path = trim( (string)$path, '/' );
        $current = self::currentSiteAccessName();
        $cacheKey = 'p|' . $path;
        if ( array_key_exists( $cacheKey, self::$targetCache ) )
        {
            return self::$targetCache[$cacheKey];
        }

        $target = false;

        foreach ( self::pathMap() as $mapPath => $siteAccess )
        {
            if ( $mapPath !== '' && self::pathStartsWith( $path, $mapPath ) )
            {
                $target = $siteAccess;
                break;
            }
        }

        if ( $target === false )
        {
            $bestLength = 0;
            $candidates = array();
            foreach ( self::participants() as $name => $info )
            {
                $prefix = $info['prefix'];
                if ( $prefix === '' || !self::pathStartsWith( $path, $prefix ) )
                {
                    continue;
                }
                $length = strlen( $prefix );
                if ( $length > $bestLength )
                {
                    $bestLength = $length;
                    $candidates = array( $name );
                }
                elseif ( $length === $bestLength )
                {
                    $candidates[] = $name;
                }
            }
            if ( count( $candidates ) > 0 )
            {
                $currentPrefix = self::currentPathPrefix();
                $prefix = self::pathPrefixForSiteAccess( $candidates[0] );
                if ( $currentPrefix !== '' && strcasecmp( $currentPrefix, $prefix ) === 0 )
                {
                    $target = $current;
                }
                else
                {
                    $target = self::preferredSiteAccess( $candidates );
                }
            }
        }

        if ( $target === false && self::ini()->hasVariable( 'MultisiteSettings', 'ResolveUnprefixedPaths' ) && self::ini()->variable( 'MultisiteSettings', 'ResolveUnprefixedPaths' ) === 'enabled' )
        {
            $node = self::resolveNode( $path, array( 'node_id' => true ) );
            if ( $node instanceof eZContentObjectTreeNode )
            {
                $target = self::targetForNode( $node, '' );
            }
        }

        self::$targetCache[$cacheKey] = $target;
        return $target;
    }

    /**
     * The participating siteaccess a node belongs to: the one whose root node
     * is the deepest on the node's path. Falls back to the path prefix of
     * $path when no root node matches.
     */
    public static function targetForNode( eZContentObjectTreeNode $node, $path )
    {
        $pathString = (string)$node->attribute( 'path_string' );
        $current = self::currentSiteAccessName();
        $bestPosition = -1;
        $candidates = array();
        foreach ( self::participants() as $name => $info )
        {
            if ( !$info['root_node_id'] )
            {
                continue;
            }
            $position = strpos( $pathString, '/' . $info['root_node_id'] . '/' );
            if ( $position === false )
            {
                continue;
            }
            if ( $position > $bestPosition )
            {
                $bestPosition = $position;
                $candidates = array( $name );
            }
            elseif ( $position === $bestPosition )
            {
                $candidates[] = $name;
            }
        }

        if ( count( $candidates ) > 0 )
        {
            if ( in_array( $current, $candidates, true ) )
            {
                return $current;
            }
            $currentRoot = self::rootNodeForSiteAccess( $current );
            if ( $currentRoot && $currentRoot === self::rootNodeForSiteAccess( $candidates[0] ) )
            {
                return $current;
            }
            return self::preferredSiteAccess( $candidates );
        }

        return $path === '' ? false : self::targetForPath( $path );
    }

    /**
     * Of several siteaccesses serving the same subtree (a site and its
     * translations), the current one, then one in the current locale, then
     * the first in SiteAccessList order.
     */
    public static function preferredSiteAccess( array $candidates )
    {
        $current = self::currentSiteAccessName();
        if ( in_array( $current, $candidates, true ) )
        {
            return $current;
        }
        $data = self::siteAccessData();
        $locale = isset( $data[$current] ) ? $data[$current]['locale'] : '';
        if ( $locale !== '' )
        {
            foreach ( $candidates as $name )
            {
                if ( isset( $data[$name] ) && $data[$name]['locale'] === $locale )
                {
                    return $name;
                }
            }
        }
        return $candidates[0];
    }

    /**
     * The address of $relativePath on $siteAccess. When the siteaccess is
     * served from the current host, the address is host-relative
     * (/bold/services), so it keeps the scheme and the port of the page it is
     * on; otherwise it is absolute (https://other.host/services).
     */
    public static function siteAccessURL( $siteAccess, $relativePath, $serverURL = 'relative', $options = array() )
    {
        $siteURL = self::siteURLForSiteAccess( $siteAccess );
        $siteURL = preg_replace( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', '', $siteURL );
        $slash = strpos( $siteURL, '/' );
        $host = $slash === false ? $siteURL : substr( $siteURL, 0, $slash );
        $basePath = $slash === false ? '' : trim( substr( $siteURL, $slash ), '/' );

        $path = '/' . $basePath;
        $relativePath = trim( (string)$relativePath, '/' );
        if ( $relativePath !== '' )
        {
            $path = rtrim( $path, '/' ) . '/' . $relativePath;
        }

        $protocol = isset( $options['protocol'] ) ? strtolower( trim( (string)$options['protocol'] ) ) : '';
        $sameHost = strcasecmp( self::stripPort( $host ), self::stripPort( eZSys::hostname() ) ) === 0;

        if ( $sameHost && $serverURL !== 'full' && empty( $options['absolute'] ) && $protocol === '' )
        {
            return $path;
        }

        $scheme = self::protocolForSiteAccess( $siteAccess, $options );
        // On the current host the current port stays (an application server on :8080).
        $authority = $sameHost ? eZSys::hostname() : $host;
        if ( $scheme === '//' )
        {
            return '//' . $authority . $path;
        }
        return $scheme . '://' . $authority . $path;
    }

    private static function stripPort( $host )
    {
        return preg_replace( '#:\d+$#', '', strtolower( (string)$host ) );
    }

    /**
     * Build the final URL for ezurl output when the extended parameters hash
     * is used (absolute, siteaccess, hash, query, no_self_link, ...).
     */
    public static function buildURL( $value, $namedParameters, $operatorName = 'ezurl' )
    {
        self::beginRequest();
        $options = self::extractOptions( $namedParameters );

        $input = self::normalizeInput( $value );
        if ( $input === false )
        {
            return self::applyQuotes( '', $options['quote_val'] );
        }

        if ( is_string( $input ) && ( preg_match( '#^[a-zA-Z0-9]+:|^//#', $input ) ) )
        {
            $url = self::appendHashAndQuery( $input, $options );
            return self::applyQuotes( $url, $options['quote_val'] );
        }

        $node = self::resolveNode( $input, $options );

        if ( self::isDisabled( $node ) )
        {
            return self::applyQuotes( '', $options['quote_val'] );
        }

        if ( $options['no_self_link'] && self::isSelfLink( $node ) )
        {
            return self::applyQuotes( '', $options['quote_val'] );
        }

        $path = self::resolvePath( $input, $node, $options );
        if ( $path === false )
        {
            return self::applyQuotes( '', $options['quote_val'] );
        }

        $forceSiteAccess = (string)$options['siteaccess'];
        $roleSiteAccess = self::roleBasedSiteAccess();
        if ( $roleSiteAccess !== '' )
        {
            $forceSiteAccess = $roleSiteAccess;
        }
        if ( $forceSiteAccess === self::currentSiteAccessName() )
        {
            $forceSiteAccess = '';
        }

        $crossInput = is_string( $input ) ? $path : $node;
        $url = ( $operatorName === 'ezurl' && $crossInput )
            ? self::crossSiteURL( $crossInput, $options['server_url'], $forceSiteAccess )
            : false;

        if ( $url !== false )
        {
            if ( $options['absolute'] || $options['protocol'] !== '' )
            {
                $url = self::absolutize( $url, $options );
            }
            $url = self::appendHashAndQuery( $url, $options );
            return self::applyQuotes( $url, $options['quote_val'] );
        }

        $url = $path;
        if ( $operatorName === 'ezroot' )
        {
            if ( $url !== '' && $url[0] !== '/' )
            {
                $url = '/' . $url;
            }
            eZURI::transformURI( $url, true, $options['server_url'] );
        }
        else
        {
            eZURI::transformURI( $url, false, $options['server_url'] );
        }

        $forceAbsolute = $options['absolute'] || self::ini()->variable( 'OperatorSettings', 'ForceAbsoluteURL' ) === 'enabled';
        if ( $forceAbsolute || $options['protocol'] !== '' )
        {
            $url = self::absolutize( $url, $options );
        }

        $url = self::appendHashAndQuery( $url, $options );

        return self::applyQuotes( $url, $options['quote_val'] );
    }

    /**
     * Make a host-relative URL absolute on the current host, or apply the
     * protocol option to an absolute one.
     */
    private static function absolutize( $url, $options )
    {
        $scheme = self::protocolForSiteAccess( self::currentSiteAccessName(), $options );
        if ( preg_match( '#^[a-zA-Z0-9]+://#', $url ) )
        {
            return preg_replace( '#^[a-zA-Z0-9]+://#', $scheme === '//' ? '//' : $scheme . '://', $url, 1 );
        }
        if ( substr( $url, 0, 2 ) === '//' )
        {
            return $scheme === '//' ? $url : $scheme . ':' . $url;
        }
        $host = eZSys::hostname();
        return ( $scheme === '//' ? '//' : $scheme . '://' ) . $host . '/' . ltrim( $url, '/' );
    }

    /**
     * Returns just the path for the input, without the prefix of the
     * siteaccess it belongs to.
     */
    public static function buildPath( $value, $namedParameters )
    {
        self::beginRequest();
        $options = self::extractOptions( $namedParameters );
        $input = self::normalizeInput( $value );
        if ( $input === false )
        {
            return '';
        }

        $node = self::resolveNode( $input, $options );
        $path = self::resolvePath( $input, $node, $options );
        if ( $path === false )
        {
            return '';
        }

        $path = trim( $path, '/' );
        $target = $options['siteaccess'] !== '' ? $options['siteaccess'] : self::targetSiteAccess( $path, $node );
        if ( $target !== '' && $target !== false )
        {
            $path = self::stripPrefix( $path, self::pathPrefixForSiteAccess( $target ) );
        }

        return $path;
    }

    /**
     * Returns the siteaccess name that should host the input.
     */
    public static function buildSiteAccessName( $value, $namedParameters )
    {
        self::beginRequest();
        $options = self::extractOptions( $namedParameters );
        $input = self::normalizeInput( $value );
        if ( $input === false )
        {
            return '';
        }

        if ( $options['siteaccess'] !== '' )
        {
            return $options['siteaccess'];
        }

        if ( is_string( $input ) && preg_match( '#^[a-zA-Z0-9]+:|^//#', $input ) )
        {
            return '';
        }

        $node = self::resolveNode( $input, $options );
        $path = self::resolvePath( $input, $node, $options );
        if ( $path === false )
        {
            return '';
        }

        $target = self::targetSiteAccess( trim( $path, '/' ), $node );
        return $target === false ? self::currentSiteAccessName() : $target;
    }

    /**
     * The target siteaccess for a path and, when known, its node.
     */
    public static function targetSiteAccess( $path, $node = false )
    {
        if ( $node instanceof eZContentObjectTreeNode )
        {
            return self::targetForNode( $node, $path );
        }
        return self::targetForPath( $path );
    }

    /**
     * Returns root node information per participating siteaccess. Each element
     * holds siteaccess, host, prefix and root_node_id.
     */
    public static function buildRoots()
    {
        $roots = array();
        foreach ( self::participants() as $name => $info )
        {
            $roots[$name] = array(
                'siteaccess' => $name,
                'host' => $info['site_url'],
                'prefix' => $info['prefix'],
                'root_node_id' => $info['root_node_id'],
            );
        }
        return $roots;
    }

    /**
     * siteaccess => root node id of every participating siteaccess.
     */
    public static function siteRootMap()
    {
        $map = array();
        foreach ( self::participants() as $name => $info )
        {
            $map[$name] = $info['root_node_id'];
        }
        return $map;
    }

    /**
     * Extract options from the named parameters array, merging with defaults and INI settings.
     */
    public static function extractOptions( $namedParameters )
    {
        $ini = self::ini();

        $options = array(
            'quote_val' => $ini->hasVariable( 'OperatorSettings', 'DefaultQuote' ) ? $ini->variable( 'OperatorSettings', 'DefaultQuote' ) : 'double',
            'server_url' => $ini->hasVariable( 'OperatorSettings', 'DefaultServerURL' ) ? $ini->variable( 'OperatorSettings', 'DefaultServerURL' ) : 'relative',
            'absolute' => false,
            'siteaccess' => '',
            'hash' => '',
            'query' => '',
            'user_parameters' => array(),
            'no_self_link' => false,
            'node_id' => true,
            'link_type' => '',
            'protocol' => '',
            'debug' => false,
        );

        if ( isset( $namedParameters['quote_val'] ) )
        {
            $options['quote_val'] = $namedParameters['quote_val'];
        }
        if ( isset( $namedParameters['server_url'] ) )
        {
            $options['server_url'] = $namedParameters['server_url'];
        }

        $extra = isset( $namedParameters['parameters'] ) && is_array( $namedParameters['parameters'] )
            ? $namedParameters['parameters']
            : array();

        foreach ( array( 'absolute', 'siteaccess', 'hash', 'query', 'user_parameters', 'no_self_link', 'node_id', 'link_type', 'protocol', 'debug' ) as $key )
        {
            if ( isset( $extra[$key] ) )
            {
                $options[$key] = $extra[$key];
            }
        }

        foreach ( array( 'absolute', 'no_self_link', 'node_id', 'debug' ) as $key )
        {
            if ( is_string( $options[$key] ) )
            {
                $options[$key] = in_array( strtolower( $options[$key] ), array( 'yes', 'true', 'enabled', '1' ) );
            }
        }
        $options['siteaccess'] = (string)$options['siteaccess'];
        $options['protocol'] = (string)$options['protocol'];
        $options['link_type'] = (string)$options['link_type'];

        return $options;
    }

    /**
     * Convert the incoming operator value into a usable form.
     */
    public static function normalizeInput( $value )
    {
        if ( is_object( $value ) )
        {
            if ( $value instanceof eZContentObjectTreeNode || $value instanceof eZContentObject )
            {
                return $value;
            }
            return method_exists( $value, '__toString' ) ? (string)$value : false;
        }

        if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) )
        {
            return (int)$value;
        }

        if ( is_string( $value ) )
        {
            return $value;
        }

        if ( $value === null )
        {
            return '';
        }

        return false;
    }

    /**
     * Resolve the input to a node object if possible. A path string is looked
     * up as a URL alias only when it is not a module address.
     */
    public static function resolveNode( $input, $options = array() )
    {
        if ( $input instanceof eZContentObjectTreeNode )
        {
            return $input;
        }

        if ( $input instanceof eZContentObject )
        {
            $mainNode = $input->mainNode();
            return $mainNode ? $mainNode : false;
        }

        if ( is_int( $input ) )
        {
            if ( !isset( $options['node_id'] ) || $options['node_id'] )
            {
                $node = eZContentObjectTreeNode::fetch( $input );
                return $node ? $node : false;
            }
            $object = eZContentObject::fetch( $input );
            return $object ? $object->mainNode() : false;
        }

        if ( is_string( $input ) && $input !== '' )
        {
            $path = trim( preg_replace( '#[?\#].*$#s', '', $input ), '/' );
            if ( $path === '' || self::isModulePath( $path ) )
            {
                return false;
            }

            $cacheKey = 'node_' . $path;
            if ( array_key_exists( $cacheKey, self::$nodeCache ) )
            {
                return self::$nodeCache[$cacheKey];
            }

            $nodeID = eZURLAliasML::fetchNodeIDByPath( $path );
            if ( !$nodeID )
            {
                $currentPrefix = self::currentPathPrefix();
                if ( $currentPrefix !== '' )
                {
                    $nodeID = eZURLAliasML::fetchNodeIDByPath( $currentPrefix . '/' . $path );
                }
            }

            $node = $nodeID ? eZContentObjectTreeNode::fetch( $nodeID ) : false;
            self::$nodeCache[$cacheKey] = $node ? $node : false;
            return self::$nodeCache[$cacheKey];
        }

        return false;
    }

    /**
     * Whether the first element of $path names a module (content/view/...,
     * user/login, tags/view/...), which is never a URL alias to look up.
     */
    public static function isModulePath( $path )
    {
        $first = strtok( trim( $path, '/' ), '/' );
        if ( $first === false || $first === '' || !preg_match( '#^[a-z0-9_]+$#', $first ) )
        {
            return false;
        }
        return eZModule::exists( $first ) instanceof eZModule;
    }

    /**
     * Resolve the path to use for URL generation.
     */
    public static function resolvePath( $input, $node, $options = array() )
    {
        if ( is_string( $input ) )
        {
            if ( $node instanceof eZContentObjectTreeNode )
            {
                $classSettings = self::classSettings( $node->attribute( 'class_identifier' ) );
                if ( $classSettings )
                {
                    return self::classBasedURL( $node, $classSettings, $options );
                }
            }
            return $input;
        }

        if ( $node instanceof eZContentObjectTreeNode )
        {
            $classSettings = self::classSettings( $node->attribute( 'class_identifier' ) );
            if ( $classSettings )
            {
                return self::classBasedURL( $node, $classSettings, $options );
            }
            return $node->attribute( 'url_alias' );
        }

        return false;
    }

    /**
     * Append hash and query string / user parameters to a URL.
     */
    public static function appendHashAndQuery( $url, $options )
    {
        $queryParts = array();

        if ( isset( $options['query'] ) && $options['query'] !== '' && $options['query'] !== array() )
        {
            if ( is_array( $options['query'] ) )
            {
                foreach ( $options['query'] as $key => $val )
                {
                    $queryParts[] = urlencode( $key ) . '=' . urlencode( (string)$val );
                }
            }
            else
            {
                $queryParts[] = $options['query'];
            }
        }

        if ( isset( $options['user_parameters'] ) && is_array( $options['user_parameters'] ) )
        {
            foreach ( $options['user_parameters'] as $key => $val )
            {
                $url = rtrim( $url, '/' ) . '/(' . urlencode( $key ) . ')/' . urlencode( (string)$val );
            }
        }

        if ( count( $queryParts ) > 0 )
        {
            $url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . implode( '&', $queryParts );
        }

        if ( isset( $options['hash'] ) && $options['hash'] !== '' )
        {
            $url .= '#' . ltrim( $options['hash'], '#' );
        }

        return $url;
    }

    /**
     * Quote a string for template output.
     */
    public static function applyQuotes( $text, $quote = 'double' )
    {
        if ( $quote === 'no' || $quote === false )
        {
            return $text;
        }
        $char = $quote === 'single' ? "'" : '"';
        return $char . $text . $char;
    }

    /**
     * Strip a path prefix from a URL path.
     */
    public static function stripPrefix( $path, $prefix )
    {
        $path = trim( (string)$path, '/' );
        if ( $prefix !== '' && self::pathStartsWith( $path, $prefix ) )
        {
            $path = ltrim( substr( $path, strlen( trim( $prefix, '/' ) ) ), '/' );
        }
        return $path;
    }

    /**
     * Check whether a path starts with a prefix, element by element.
     */
    public static function pathStartsWith( $path, $prefix )
    {
        $path = trim( (string)$path, '/' );
        $prefix = trim( (string)$prefix, '/' );
        if ( $prefix === '' )
        {
            return false;
        }
        return preg_match( '#^' . preg_quote( $prefix, '#' ) . '(/|$)#i', $path ) === 1;
    }

    /**
     * Data of every available siteaccess: prefix, site_url, require_login,
     * locale and root_node_id. Read once per process from the INI files.
     */
    public static function siteAccessData()
    {
        if ( self::$siteAccessData !== null )
        {
            return self::$siteAccessData;
        }

        $data = array();
        $ini = self::ini();
        $hostMap = $ini->hasVariable( 'HostMap', 'SiteAccess' ) ? (array)$ini->variable( 'HostMap', 'SiteAccess' ) : array();
        $rootMap = $ini->hasVariable( 'MultisiteSettings', 'RootNodeList' ) ? (array)$ini->variable( 'MultisiteSettings', 'RootNodeList' ) : array();

        foreach ( eZSiteAccess::siteAccessList() as $siteAccessItem )
        {
            $name = $siteAccessItem['name'];
            $siteIni = eZINI::getSiteAccessIni( $name, 'site.ini' );
            if ( !$siteIni instanceof eZINI )
            {
                continue;
            }

            $prefix = $siteIni->hasVariable( 'SiteAccessSettings', 'PathPrefix' ) ? (string)$siteIni->variable( 'SiteAccessSettings', 'PathPrefix' ) : '';
            $siteURL = $siteIni->hasVariable( 'SiteSettings', 'SiteURL' ) ? (string)$siteIni->variable( 'SiteSettings', 'SiteURL' ) : '';
            $requireLogin = $siteIni->hasVariable( 'SiteAccessSettings', 'RequireUserLogin' )
                && strtolower( (string)$siteIni->variable( 'SiteAccessSettings', 'RequireUserLogin' ) ) === 'true';
            $locale = $siteIni->hasVariable( 'RegionalSettings', 'Locale' ) ? (string)$siteIni->variable( 'RegionalSettings', 'Locale' ) : '';

            if ( isset( $hostMap[$name] ) && $hostMap[$name] !== '' )
            {
                $siteURL = $hostMap[$name];
            }

            $rootNodeID = 0;
            if ( isset( $rootMap[$name] ) && (int)$rootMap[$name] > 0 )
            {
                $rootNodeID = (int)$rootMap[$name];
            }
            else
            {
                $contentIni = eZINI::getSiteAccessIni( $name, 'content.ini' );
                if ( $contentIni instanceof eZINI && $contentIni->hasVariable( 'NodeSettings', 'RootNode' ) )
                {
                    $rootNodeID = (int)$contentIni->variable( 'NodeSettings', 'RootNode' );
                }
                // Node 2 is the content root of the whole installation, not a site.
                if ( $rootNodeID <= 2 )
                {
                    $rootNodeID = 0;
                    $indexPage = $siteIni->hasVariable( 'SiteSettings', 'IndexPage' ) ? (string)$siteIni->variable( 'SiteSettings', 'IndexPage' ) : '';
                    if ( preg_match( '#content/view/[a-z_]+/(\d+)#', $indexPage, $matches ) && (int)$matches[1] > 2 )
                    {
                        $rootNodeID = (int)$matches[1];
                    }
                }
            }

            $data[$name] = array(
                'prefix' => trim( $prefix, '/' ),
                'site_url' => trim( $siteURL, '/' ),
                'require_login' => $requireLogin,
                'locale' => $locale,
                'root_node_id' => $rootNodeID,
            );
        }

        self::$siteAccessData = $data;
        return self::$siteAccessData;
    }

    /**
     * The siteaccesses that take part in cross-site linking, in order:
     * [MultisiteSettings] SiteAccessList[], or every public siteaccess that
     * has a PathPrefix or a configured root node.
     */
    public static function participants()
    {
        if ( self::$participants !== null )
        {
            return self::$participants;
        }

        $data = self::siteAccessData();
        $ini = self::ini();
        $list = $ini->hasVariable( 'MultisiteSettings', 'SiteAccessList' ) ? (array)$ini->variable( 'MultisiteSettings', 'SiteAccessList' ) : array();
        $list = array_values( array_filter( $list, 'strlen' ) );
        $rootMap = $ini->hasVariable( 'MultisiteSettings', 'RootNodeList' ) ? (array)$ini->variable( 'MultisiteSettings', 'RootNodeList' ) : array();

        $participants = array();
        if ( count( $list ) > 0 )
        {
            foreach ( $list as $name )
            {
                if ( isset( $data[$name] ) )
                {
                    $participants[$name] = $data[$name];
                }
            }
        }
        else
        {
            foreach ( $data as $name => $info )
            {
                if ( !$info['require_login'] && ( $info['prefix'] !== '' || isset( $rootMap[$name] ) ) )
                {
                    $participants[$name] = $info;
                }
            }
        }

        self::$participants = $participants;
        return self::$participants;
    }

    public static function isParticipant( $siteAccess )
    {
        $participants = self::participants();
        return $siteAccess !== '' && isset( $participants[$siteAccess] );
    }

    public static function rootNodeForSiteAccess( $siteAccess )
    {
        $data = self::siteAccessData();
        return isset( $data[$siteAccess] ) ? $data[$siteAccess]['root_node_id'] : 0;
    }

    /**
     * Prefix map (prefix => siteaccess) of the participating siteaccesses,
     * longest prefix first; the first siteaccess wins for a shared prefix.
     */
    public static function prefixMap()
    {
        $map = array();
        foreach ( self::participants() as $name => $info )
        {
            if ( $info['prefix'] !== '' && !isset( $map[$info['prefix']] ) )
            {
                $map[$info['prefix']] = $name;
            }
        }
        uksort( $map, array( 'eZURLMultisite', 'comparePrefixLength' ) );
        return $map;
    }

    /**
     * Convention-based path map sorted by longest path first.
     * Maps a path to an explicit target siteaccess, overriding prefix detection.
     */
    public static function pathMap()
    {
        if ( self::$pathMap !== null )
        {
            return self::$pathMap;
        }

        $map = array();
        $ini = self::ini();
        if ( $ini->hasVariable( 'PathMap', 'Path' ) )
        {
            foreach ( (array)$ini->variable( 'PathMap', 'Path' ) as $path => $siteAccess )
            {
                $map[trim( (string)$path, '/' )] = $siteAccess;
            }
        }

        uksort( $map, array( 'eZURLMultisite', 'comparePrefixLength' ) );
        self::$pathMap = $map;
        return self::$pathMap;
    }

    /**
     * Return the path prefix for a siteaccess.
     */
    public static function pathPrefixForSiteAccess( $siteAccess )
    {
        $data = self::siteAccessData();
        return isset( $data[$siteAccess] ) ? $data[$siteAccess]['prefix'] : '';
    }

    /**
     * Return the site URL for a siteaccess, falling back to current hostname.
     */
    public static function siteURLForSiteAccess( $siteAccess )
    {
        $data = self::siteAccessData();
        if ( isset( $data[$siteAccess] ) && $data[$siteAccess]['site_url'] !== '' )
        {
            return $data[$siteAccess]['site_url'];
        }
        return eZSys::hostname();
    }

    /**
     * Determine the current siteaccess name.
     */
    public static function currentSiteAccessName()
    {
        $current = eZSiteAccess::current();
        return is_array( $current ) && isset( $current['name'] ) ? (string)$current['name'] : '';
    }

    /**
     * Determine the current path prefix.
     */
    public static function currentPathPrefix()
    {
        return self::pathPrefixForSiteAccess( self::currentSiteAccessName() );
    }

    /**
     * Return the protocol to use for a siteaccess, optionally overridden by parameters.
     * Supported parameter values: '', 'http', 'https', '//' (protocol-relative).
     */
    public static function protocolForSiteAccess( $siteAccess, $options = array() )
    {
        $protocol = isset( $options['protocol'] ) ? strtolower( trim( (string)$options['protocol'] ) ) : '';

        if ( $protocol === 'http' || $protocol === 'https' || $protocol === '//' )
        {
            return $protocol;
        }

        return self::currentScheme();
    }

    /**
     * Return the current scheme (http/https).
     */
    public static function currentScheme()
    {
        return eZSys::isSSLNow() ? 'https' : 'http';
    }

    /**
     * Pick the best empty-prefix siteaccess for cross-site links.
     */
    public static function emptyPrefixSiteAccess()
    {
        self::beginRequest();
        if ( self::$emptyPrefixSiteAccess !== null )
        {
            return self::$emptyPrefixSiteAccess;
        }

        $current = self::currentSiteAccessName();
        $defaultAccess = eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' );
        $candidates = array();

        foreach ( self::participants() as $name => $info )
        {
            if ( $name !== $current && $info['prefix'] === '' && $info['site_url'] !== '' && !$info['require_login'] )
            {
                $candidates[] = $name;
            }
        }

        if ( count( $candidates ) === 0 )
        {
            self::$emptyPrefixSiteAccess = false;
        }
        elseif ( in_array( $defaultAccess, $candidates ) )
        {
            self::$emptyPrefixSiteAccess = $defaultAccess;
        }
        else
        {
            usort( $candidates, array( 'eZURLMultisite', 'compareSiteURL' ) );
            self::$emptyPrefixSiteAccess = $candidates[0];
        }
        return self::$emptyPrefixSiteAccess;
    }

    /**
     * Compare siteaccess URLs by length.
     */
    public static function compareSiteURL( $a, $b )
    {
        $data = self::siteAccessData();
        return strlen( $data[$a]['site_url'] ) - strlen( $data[$b]['site_url'] );
    }

    /**
     * Compare prefixes by length, longest first.
     */
    public static function comparePrefixLength( $a, $b )
    {
        return strlen( (string)$b ) - strlen( (string)$a );
    }

    /**
     * Check if the resolved node matches the current page's node.
     */
    public static function isSelfLink( $node )
    {
        if ( !( $node instanceof eZContentObjectTreeNode ) )
        {
            return false;
        }

        self::beginRequest();
        if ( self::$currentNodeID === null )
        {
            $currentNode = false;
            if ( isset( $GLOBALS['eZRequestedModuleParams']['parameters']['NodeID'] ) )
            {
                $currentNode = (int)$GLOBALS['eZRequestedModuleParams']['parameters']['NodeID'];
            }
            elseif ( isset( $_GET['NodeID'] ) )
            {
                $currentNode = (int)$_GET['NodeID'];
            }
            self::$currentNodeID = $currentNode;
        }

        if ( self::$currentNodeID === false )
        {
            return false;
        }

        return (int)$node->attribute( 'node_id' ) === (int)self::$currentNodeID;
    }

    /**
     * Check if the node/object should not be linked.
     */
    public static function isDisabled( $node )
    {
        if ( !( $node instanceof eZContentObjectTreeNode ) )
        {
            return false;
        }

        $ini = self::ini();

        if ( $ini->variable( 'PathSettings', 'DisableNodeID' ) === 'enabled' &&
             $ini->hasVariable( 'PathSettings', 'NodeIDList' ) )
        {
            $list = array_map( 'intval', (array)$ini->variable( 'PathSettings', 'NodeIDList' ) );
            if ( in_array( (int)$node->attribute( 'node_id' ), $list, true ) )
            {
                return true;
            }
        }

        if ( $ini->variable( 'PathSettings', 'DisableObjectID' ) === 'enabled' &&
             $ini->hasVariable( 'PathSettings', 'ObjectIDList' ) )
        {
            $list = array_map( 'intval', (array)$ini->variable( 'PathSettings', 'ObjectIDList' ) );
            if ( in_array( (int)$node->attribute( 'contentobject_id' ), $list, true ) )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the class settings array for a class identifier, or false if none.
     */
    public static function classSettings( $classIdentifier )
    {
        $ini = self::ini();
        $siteLinkClasses = $ini->hasVariable( 'OperatorSettings', 'SiteLinkClassList' ) ? (array)$ini->variable( 'OperatorSettings', 'SiteLinkClassList' ) : array();

        if ( !in_array( $classIdentifier, $siteLinkClasses ) )
        {
            return false;
        }

        $settings = array(
            'default_link_type' => $ini->hasVariable( 'ClassSettings', 'DefaultLinkType' ) ? $ini->variable( 'ClassSettings', 'DefaultLinkType' ) : 'internal',
            'self_linking' => $ini->hasVariable( 'ClassSettings', 'SelfLinking' ) ? $ini->variable( 'ClassSettings', 'SelfLinking' ) : 'enabled',
            'link_type_list' => array(),
            'datatype_class' => false,
        );

        if ( $ini->hasVariable( 'ClassSettings', 'LinkTypeList' ) )
        {
            $settings['link_type_list'] = $ini->variable( 'ClassSettings', 'LinkTypeList' );
        }

        if ( $ini->hasGroup( $classIdentifier ) )
        {
            if ( $ini->hasVariable( $classIdentifier, 'DefaultLinkType' ) )
            {
                $settings['default_link_type'] = $ini->variable( $classIdentifier, 'DefaultLinkType' );
            }
            if ( $ini->hasVariable( $classIdentifier, 'SelfLinking' ) )
            {
                $settings['self_linking'] = $ini->variable( $classIdentifier, 'SelfLinking' );
            }
            if ( $ini->hasVariable( $classIdentifier, 'LinkTypeList' ) )
            {
                $settings['link_type_list'] = $ini->variable( $classIdentifier, 'LinkTypeList' );
            }
            if ( $ini->hasVariable( $classIdentifier, 'DataTypeClass' ) )
            {
                $settings['datatype_class'] = $ini->variable( $classIdentifier, 'DataTypeClass' );
            }
        }

        return $settings;
    }

    /**
     * Generate a URL based on class settings.
     */
    public static function classBasedURL( $node, $classSettings, $options = array() )
    {
        $linkType = isset( $options['link_type'] ) && $options['link_type'] !== '' ? $options['link_type'] : $classSettings['default_link_type'];

        if ( !isset( $classSettings['link_type_list'][$linkType] ) )
        {
            return $node->attribute( 'url_alias' );
        }

        $attributeIdentifier = $classSettings['link_type_list'][$linkType];
        $object = $node->attribute( 'object' );
        if ( !$object )
        {
            return $node->attribute( 'url_alias' );
        }

        $dataMap = $object->attribute( 'data_map' );
        if ( !isset( $dataMap[$attributeIdentifier] ) )
        {
            return $node->attribute( 'url_alias' );
        }

        $attribute = $dataMap[$attributeIdentifier];
        $handlerClass = $classSettings['datatype_class'];

        if ( !$handlerClass && $attribute )
        {
            $handlerClass = self::handlerForDataType( $attribute->attribute( 'data_type_string' ) );
        }

        if ( $handlerClass && class_exists( $handlerClass ) )
        {
            $handler = new $handlerClass();
            if ( $handler instanceof eZURLMultisiteDataTypeHandler )
            {
                $url = $handler->getURL( $attribute, $linkType, $options );
                if ( $url !== false )
                {
                    return $url;
                }
            }
        }

        return $node->attribute( 'url_alias' );
    }

    /**
     * Look up the datatype handler class for a datatype string.
     */
    public static function handlerForDataType( $dataTypeString )
    {
        $ini = self::ini();
        if ( $ini->hasVariable( 'DataTypeSettings', 'HandlerList' ) )
        {
            $handlers = $ini->variable( 'DataTypeSettings', 'HandlerList' );
            if ( isset( $handlers[$dataTypeString] ) )
            {
                return $handlers[$dataTypeString];
            }
        }

        $handlers = array(
            'ezurl' => 'eZURLMultisiteURLHandler',
            'ezstring' => 'eZURLMultisiteURLHandler',
            'ezbinaryfile' => 'eZURLMultisiteBinaryFileHandler',
            'ezobjectrelation' => 'eZURLMultisiteObjectRelationHandler',
        );

        return isset( $handlers[$dataTypeString] ) ? $handlers[$dataTypeString] : false;
    }

    /**
     * Return a siteaccess forced by the current user's role, or empty string.
     */
    public static function roleBasedSiteAccess()
    {
        $ini = self::ini();
        if ( $ini->variable( 'OperatorSettings', 'RoleOverride' ) !== 'enabled' )
        {
            return '';
        }

        if ( !$ini->hasVariable( 'RoleMap', 'RoleList' ) )
        {
            return '';
        }

        $roleList = $ini->variable( 'RoleMap', 'RoleList' );
        if ( !is_array( $roleList ) || count( $roleList ) === 0 )
        {
            return '';
        }

        $currentUser = eZUser::currentUser();
        if ( !is_object( $currentUser ) )
        {
            return '';
        }

        foreach ( $currentUser->roleIDList() as $roleID )
        {
            if ( isset( $roleList[$roleID] ) )
            {
                return $roleList[$roleID];
            }
        }

        return '';
    }

    /**
     * Return the list of PathPrefixExclude entries from site.ini.
     */
    public static function pathPrefixExcludeList()
    {
        $ini = eZINI::instance();
        if ( $ini->hasVariable( 'SiteAccessSettings', 'PathPrefixExclude' ) )
        {
            return (array)$ini->variable( 'SiteAccessSettings', 'PathPrefixExclude' );
        }
        return array();
    }

    /**
     * Check if a path should be excluded from PathPrefix handling.
     */
    public static function isPathPrefixExcluded( $path )
    {
        foreach ( self::pathPrefixExcludeList() as $exclude )
        {
            if ( $exclude !== '' && self::pathStartsWith( $path, $exclude ) )
            {
                return true;
            }
        }
        return false;
    }
}

?>
