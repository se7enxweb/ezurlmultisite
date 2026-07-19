<?php

class eZURLMultisite
{
    private static $siteAccessData = null;
    private static $nodeCache = array();
    private static $targetCache = array();
    private static $emptyPrefixSiteAccess = null;
    private static $currentNodeID = null;
    private static $ini = null;
    private static $pathMap = null;

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

    /**
     * Resets static caches. Useful in long-running processes/tests.
     */
    public static function resetCache()
    {
        self::$siteAccessData = null;
        self::$nodeCache = array();
        self::$targetCache = array();
        self::$emptyPrefixSiteAccess = null;
        self::$currentNodeID = null;
        self::$ini = null;
        self::$pathMap = null;
    }

    /**
     * Build the final URL for ezurl output.
     */
    public static function buildURL( $value, $namedParameters, $operatorName = 'ezurl' )
    {
        $options = self::extractOptions( $namedParameters );

        $input = self::normalizeInput( $value );
        if ( $input === false )
        {
            return self::applyQuotes( '', $options['quote_val'] );
        }

        if ( is_string( $input ) && ( preg_match( '#^[a-zA-Z0-9]+:|^//#', $input ) ) )
        {
            $url = $input;
            $url = self::appendHashAndQuery( $url, $options );
            return self::applyQuotes( $url, $options['quote_val'] );
        }

        $node = self::resolveNode( $input, $options );

        if ( self::isDisabled( $node ) )
        {
            return self::applyQuotes( '', $options['quote_val'] );
        }

        if ( self::isSelfLink( $node ) && $options['no_self_link'] )
        {
            return self::applyQuotes( '', $options['quote_val'] );
        }

        $path = self::resolvePath( $input, $node, $options );
        if ( $path === false || $path === '' )
        {
            return self::applyQuotes( '', $options['quote_val'] );
        }

        $path = eZURLAliasML::cleanURL( $path );

        $targetSiteAccess = $options['siteaccess'];
        if ( $targetSiteAccess === '' )
        {
            $targetSiteAccess = self::targetSiteAccess( $path, $node );
        }

        $roleSiteAccess = self::roleBasedSiteAccess();
        if ( $roleSiteAccess !== '' )
        {
            $targetSiteAccess = $roleSiteAccess;
        }

        $currentSiteAccess = self::currentSiteAccessName();

        $forceAbsolute = $options['absolute'];
        if ( !$forceAbsolute && self::ini()->variable( 'OperatorSettings', 'ForceAbsoluteURL' ) === 'enabled' )
        {
            $forceAbsolute = true;
        }

        if ( $targetSiteAccess !== '' && $targetSiteAccess !== false && $targetSiteAccess !== $currentSiteAccess )
        {
            $url = self::buildExternalURL( $targetSiteAccess, $path, $options );
            return self::applyQuotes( $url, $options['quote_val'] );
        }

        $url = $path;
        if ( $operatorName === 'ezroot' )
        {
            if ( !preg_match( '#^[a-zA-Z0-9]+:|^//#', $url ) )
            {
                if ( $url !== '' && $url[0] !== '/' )
                {
                    $url = '/' . $url;
                }
            }
            eZURI::transformURI( $url, true, $options['server_url'] );
        }
        else
        {
            if ( !preg_match( '#^[a-zA-Z0-9]+:|^//#', $url ) )
            {
                eZURI::transformURI( $url, false, $options['server_url'] );
            }
        }

        // Honor an explicit protocol override for server_url='full' output.
        if ( $options['protocol'] !== '' && preg_match( '#^[a-zA-Z0-9]+://#', $url ) )
        {
            $scheme = self::protocolForSiteAccess( $currentSiteAccess, $options );
            if ( $scheme === '//' )
            {
                $url = preg_replace( '#^[a-zA-Z0-9]+://#', '//', $url, 1 );
            }
            else
            {
                $url = preg_replace( '#^[a-zA-Z0-9]+://#', $scheme . '://', $url, 1 );
            }
        }

        if ( $forceAbsolute && !preg_match( '#^[a-zA-Z0-9]+:|^//#', $url ) )
        {
            $scheme = self::protocolForSiteAccess( $currentSiteAccess, $options );
            if ( $scheme === '//' )
            {
                $url = '//' . self::siteURLForSiteAccess( $currentSiteAccess ) . $url;
            }
            else
            {
                $url = $scheme . '://' . self::siteURLForSiteAccess( $currentSiteAccess ) . $url;
            }
        }

        $url = self::appendHashAndQuery( $url, $options );

        return self::applyQuotes( $url, $options['quote_val'] );
    }

    /**
     * Returns just the path for the input.
     */
    public static function buildPath( $value, $namedParameters )
    {
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

        $path = eZURLAliasML::cleanURL( $path );
        $targetSiteAccess = $options['siteaccess'];
        if ( $targetSiteAccess === '' )
        {
            $targetSiteAccess = self::targetSiteAccess( $path, $node );
        }

        if ( $targetSiteAccess !== '' && $targetSiteAccess !== false )
        {
            $prefix = self::pathPrefixForSiteAccess( $targetSiteAccess );
            $path = self::stripPrefix( $path, $prefix );
        }

        return $path;
    }

    /**
     * Returns the siteaccess name that should host the input.
     */
    public static function buildSiteAccessName( $value, $namedParameters )
    {
        $options = self::extractOptions( $namedParameters );
        $input = self::normalizeInput( $value );
        if ( $input === false )
        {
            return '';
        }

        if ( is_string( $input ) && preg_match( '#^[a-zA-Z0-9]+:|^//#', $input ) )
        {
            return '';
        }

        $node = self::resolveNode( $input, $options );
        $path = self::resolvePath( $input, $node, $options );
        if ( $path === false || $path === '' )
        {
            return '';
        }

        $path = eZURLAliasML::cleanURL( $path );

        if ( $options['siteaccess'] !== '' )
        {
            return $options['siteaccess'];
        }

        $target = self::targetSiteAccess( $path, $node );
        if ( $target === false )
        {
            return self::currentSiteAccessName();
        }

        return $target;
    }

    /**
     * Returns an array of root node IDs per siteaccess/host.
     */
    public static function buildRoots()
    {
        $roots = array();
        foreach ( self::siteAccessData() as $name => $info )
        {
            $rootNode = false;
            $siteIni = eZINI::getSiteAccessIni( $name, 'site.ini' );
            if ( $siteIni->hasVariable( 'NodeSettings', 'RootNode' ) )
            {
                $rootNode = (int)$siteIni->variable( 'NodeSettings', 'RootNode' );
            }

            $roots[$name] = array(
                'siteaccess' => $name,
                'host' => $info['site_url'],
                'prefix' => $info['prefix'],
                'root_node_id' => $rootNode,
            );
        }
        return $roots;
    }

    /**
     * Extract options from the named parameters array, merging with defaults and INI settings.
     */
    public static function extractOptions( $namedParameters )
    {
        $ini = self::ini();

        $defaults = array(
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

        $options = $defaults;

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

        if ( is_string( $options['absolute'] ) )
        {
            $options['absolute'] = in_array( strtolower( $options['absolute'] ), array( 'yes', 'true', 'enabled', '1' ) );
        }
        if ( is_string( $options['no_self_link'] ) )
        {
            $options['no_self_link'] = in_array( strtolower( $options['no_self_link'] ), array( 'yes', 'true', 'enabled', '1' ) );
        }
        if ( is_string( $options['node_id'] ) )
        {
            $options['node_id'] = in_array( strtolower( $options['node_id'] ), array( 'yes', 'true', 'enabled', '1' ) );
        }
        if ( is_string( $options['debug'] ) )
        {
            $options['debug'] = in_array( strtolower( $options['debug'] ), array( 'yes', 'true', 'enabled', '1' ) );
        }

        return $options;
    }

    /**
     * Convert the incoming operator value into a usable form.
     */
    public static function normalizeInput( &$value )
    {
        if ( is_object( $value ) )
        {
            if ( $value instanceof eZContentObjectTreeNode )
            {
                return $value;
            }
            elseif ( $value instanceof eZContentObject )
            {
                return $value;
            }
            return (string)$value;
        }

        if ( is_numeric( $value ) )
        {
            return (int)$value;
        }

        if ( is_string( $value ) )
        {
            return $value;
        }

        return false;
    }

    /**
     * Resolve the input to a node object if possible.
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
            if ( $options['node_id'] )
            {
                return eZContentObjectTreeNode::fetch( $input );
            }
            else
            {
                $object = eZContentObject::fetch( $input );
                return $object ? $object->mainNode() : false;
            }
        }

        if ( is_string( $input ) && $input !== '' )
        {
            $path = eZURLAliasML::cleanURL( $input );
            if ( $path === '' )
            {
                return false;
            }

            $cacheKey = 'node_' . $path;
            if ( isset( self::$nodeCache[$cacheKey] ) )
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

            if ( $nodeID )
            {
                $node = eZContentObjectTreeNode::fetch( $nodeID );
                self::$nodeCache[$cacheKey] = $node;
                return $node;
            }

            self::$nodeCache[$cacheKey] = false;
        }

        return false;
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
                $classIdentifier = $node->attribute( 'class_identifier' );
                $classSettings = self::classSettings( $classIdentifier );
                if ( $classSettings )
                {
                    return self::classBasedURL( $node, $classSettings, $options );
                }
            }

            return $input;
        }

        if ( $node instanceof eZContentObjectTreeNode )
        {
            $classIdentifier = $node->attribute( 'class_identifier' );
            $classSettings = self::classSettings( $classIdentifier );
            if ( $classSettings )
            {
                return self::classBasedURL( $node, $classSettings, $options );
            }

            return $node->attribute( 'url_alias' );
        }

        if ( $input instanceof eZContentObject )
        {
            $mainNode = $input->mainNode();
            if ( $mainNode )
            {
                return self::resolvePath( '', $mainNode, $options );
            }
        }

        return false;
    }

    /**
     * Determine target siteaccess from prefix map or node tree path.
     */
    public static function targetSiteAccess( $path, $node = false )
    {
        $path = eZURLAliasML::cleanURL( $path );
        $cacheKey = $path . '|' . self::currentSiteAccessName();
        if ( isset( self::$targetCache[$cacheKey] ) )
        {
            return self::$targetCache[$cacheKey];
        }

        foreach ( self::pathMap() as $mapPath => $siteAccess )
        {
            if ( $mapPath !== '' && self::pathStartsWith( $path, $mapPath ) )
            {
                self::$targetCache[$cacheKey] = $siteAccess;
                return $siteAccess;
            }
        }

        foreach ( self::prefixMap() as $prefix => $siteAccess )
        {
            if ( $prefix !== '' && self::pathStartsWith( $path, $prefix ) )
            {
                self::$targetCache[$cacheKey] = $siteAccess;
                return $siteAccess;
            }
        }

        $currentPrefix = self::currentPathPrefix();
        if ( $currentPrefix !== '' && $node instanceof eZContentObjectTreeNode )
        {
            $fullPath = $node->attribute( 'path_with_names' );
            if ( !$fullPath )
            {
                $fullPath = $node->attribute( 'path_identification_string' );
            }
            $fullPath = eZURLAliasML::cleanURL( $fullPath );

            foreach ( self::pathMap() as $mapPath => $siteAccess )
            {
                if ( $mapPath !== '' && self::pathStartsWith( $fullPath, $mapPath ) )
                {
                    self::$targetCache[$cacheKey] = $siteAccess;
                    return $siteAccess;
                }
            }

            foreach ( self::prefixMap() as $prefix => $siteAccess )
            {
                if ( $prefix !== '' && self::pathStartsWith( $fullPath, $prefix ) )
                {
                    self::$targetCache[$cacheKey] = $siteAccess;
                    return $siteAccess;
                }
            }

            if ( $currentPrefix !== '' && self::pathStartsWith( $fullPath, $currentPrefix ) )
            {
                self::$targetCache[$cacheKey] = self::currentSiteAccessName();
                return self::currentSiteAccessName();
            }

            $emptyPrefixSiteAccess = self::emptyPrefixSiteAccess();
            if ( $emptyPrefixSiteAccess !== false )
            {
                self::$targetCache[$cacheKey] = $emptyPrefixSiteAccess;
                return $emptyPrefixSiteAccess;
            }
        }

        self::$targetCache[$cacheKey] = false;
        return false;
    }

    /**
     * Build an absolute external URL for a target siteaccess.
     */
    public static function buildExternalURL( $siteAccess, $path, $options = array() )
    {
        $prefix = self::pathPrefixForSiteAccess( $siteAccess );
        $relativePath = self::stripPrefix( $path, $prefix );
        $siteURL = self::siteURLForSiteAccess( $siteAccess );
        $scheme = self::protocolForSiteAccess( $siteAccess, $options );

        if ( $scheme === '//' )
        {
            $url = '//' . $siteURL . '/' . $relativePath;
        }
        else
        {
            $url = $scheme . '://' . $siteURL . '/' . $relativePath;
        }

        $url = self::appendHashAndQuery( $url, $options );
        return $url;
    }

    /**
     * Append hash and query string / user parameters to a URL.
     */
    public static function appendHashAndQuery( $url, $options )
    {
        $queryParts = array();

        if ( isset( $options['query'] ) && $options['query'] !== '' )
        {
            if ( is_array( $options['query'] ) )
            {
                foreach ( $options['query'] as $key => $val )
                {
                    $queryParts[] = urlencode( $key ) . '=' . urlencode( $val );
                }
            }
            else
            {
                $queryParts[] = $options['query'];
            }
        }

        if ( isset( $options['user_parameters'] ) && is_array( $options['user_parameters'] ) && count( $options['user_parameters'] ) > 0 )
        {
            foreach ( $options['user_parameters'] as $key => $val )
            {
                $url = rtrim( $url, '/' ) . '/(' . urlencode( $key ) . ')/' . urlencode( $val );
            }
        }

        if ( count( $queryParts ) > 0 )
        {
            $url .= '?' . implode( '&', $queryParts );
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
        $char = '"';
        if ( $quote === 'single' )
        {
            $char = "'";
        }
        elseif ( $quote === 'no' || $quote === false )
        {
            return $text;
        }

        return $char . $text . $char;
    }

    /**
     * Strip a path prefix from a URL path.
     */
    public static function stripPrefix( $path, $prefix )
    {
        if ( $prefix !== '' && self::pathStartsWith( $path, $prefix ) )
        {
            $path = substr( $path, strlen( $prefix ) );
            $path = ltrim( $path, '/' );
        }
        return $path;
    }

    /**
     * Check whether a path starts with a prefix.
     */
    public static function pathStartsWith( $path, $prefix )
    {
        $path = eZURLAliasML::cleanURL( $path );
        $prefix = eZURLAliasML::cleanURL( $prefix );
        if ( $prefix === '' )
        {
            return false;
        }
        return preg_match( '#^' . preg_quote( $prefix, '#' ) . '(/|$)#i', $path ) === 1;
    }

    /**
     * Cached siteaccess data: prefix, site_url, require_login, host override.
     */
    public static function siteAccessData()
    {
        if ( self::$siteAccessData !== null )
        {
            return self::$siteAccessData;
        }

        self::$siteAccessData = array();

        $ini = self::ini();
        $hostMap = array();
        if ( $ini->hasVariable( 'HostMap', 'SiteAccess' ) )
        {
            $hostMap = $ini->variable( 'HostMap', 'SiteAccess' );
        }

        foreach ( eZSiteAccess::siteAccessList() as $siteAccessItem )
        {
            $name = $siteAccessItem['name'];
            $siteIni = eZINI::getSiteAccessIni( $name, 'site.ini' );

            $prefix = $siteIni->hasVariable( 'SiteAccessSettings', 'PathPrefix' )
                ? $siteIni->variable( 'SiteAccessSettings', 'PathPrefix' )
                : '';
            $siteURL = $siteIni->hasVariable( 'SiteSettings', 'SiteURL' )
                ? $siteIni->variable( 'SiteSettings', 'SiteURL' )
                : '';
            $requireLogin = $siteIni->hasVariable( 'SiteAccessSettings', 'RequireUserLogin' )
                ? ( strtolower( $siteIni->variable( 'SiteAccessSettings', 'RequireUserLogin' ) ) === 'true' )
                : false;

            if ( isset( $hostMap[$name] ) && $hostMap[$name] !== '' )
            {
                $siteURL = $hostMap[$name];
            }

            self::$siteAccessData[$name] = array(
                'prefix' => eZURLAliasML::cleanURL( $prefix ),
                'site_url' => trim( $siteURL, '/' ),
                'require_login' => $requireLogin,
            );
        }

        return self::$siteAccessData;
    }

    /**
     * Prefix map sorted by longest prefix first.
     */
    public static function prefixMap()
    {
        $map = array();
        foreach ( self::siteAccessData() as $name => $info )
        {
            if ( $info['prefix'] !== '' )
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
            $map = $ini->variable( 'PathMap', 'Path' );
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
        return is_array( $current ) && isset( $current['name'] ) ? $current['name'] : '';
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
        $protocol = isset( $options['protocol'] ) ? $options['protocol'] : '';
        $protocol = strtolower( trim( $protocol ) );

        if ( $protocol === 'http' || $protocol === 'https' )
        {
            return $protocol;
        }

        if ( $protocol === '//' )
        {
            return '//';
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
        if ( self::$emptyPrefixSiteAccess !== null )
        {
            return self::$emptyPrefixSiteAccess;
        }

        $current = self::currentSiteAccessName();
        $defaultAccess = eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' );
        $candidates = array();

        foreach ( self::siteAccessData() as $name => $info )
        {
            if ( $name !== $current && $info['prefix'] === '' && $info['site_url'] !== '' && !$info['require_login'] )
            {
                $candidates[] = $name;
            }
        }

        if ( count( $candidates ) === 0 )
        {
            self::$emptyPrefixSiteAccess = false;
            return false;
        }

        if ( in_array( $defaultAccess, $candidates ) )
        {
            self::$emptyPrefixSiteAccess = $defaultAccess;
            return $defaultAccess;
        }

        usort( $candidates, array( 'eZURLMultisite', 'compareSiteURL' ) );
        self::$emptyPrefixSiteAccess = $candidates[0];
        return $candidates[0];
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
        return strlen( $b ) - strlen( $a );
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

        if ( self::$currentNodeID === null )
        {
            $currentNode = false;
            if ( isset( $GLOBALS['eZRequestedModuleParams'] ) &&
                 isset( $GLOBALS['eZRequestedModuleParams']['parameters'] ) &&
                 isset( $GLOBALS['eZRequestedModuleParams']['parameters']['NodeID'] ) )
            {
                $currentNode = $GLOBALS['eZRequestedModuleParams']['parameters']['NodeID'];
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
            $list = $ini->variable( 'PathSettings', 'NodeIDList' );
            if ( in_array( (int)$node->attribute( 'node_id' ), $list ) )
            {
                return true;
            }
        }

        if ( $ini->variable( 'PathSettings', 'DisableObjectID' ) === 'enabled' &&
             $ini->hasVariable( 'PathSettings', 'ObjectIDList' ) )
        {
            $list = $ini->variable( 'PathSettings', 'ObjectIDList' );
            if ( in_array( (int)$node->attribute( 'contentobject_id' ), $list ) )
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
        $siteLinkClasses = $ini->variable( 'OperatorSettings', 'SiteLinkClassList' );

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
        $linkType = $options['link_type'] !== '' ? $options['link_type'] : $classSettings['default_link_type'];

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
            $dataTypeString = $attribute->attribute( 'data_type_string' );
            $handlerClass = self::handlerForDataType( $dataTypeString );
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

        $roleIDList = $currentUser->roleIDList();
        foreach ( $roleIDList as $roleID )
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
            return $ini->variable( 'SiteAccessSettings', 'PathPrefixExclude' );
        }
        return array();
    }

    /**
     * Check if a path should be excluded from PathPrefix handling.
     */
    public static function isPathPrefixExcluded( $path )
    {
        $path = eZURLAliasML::cleanURL( $path );
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
