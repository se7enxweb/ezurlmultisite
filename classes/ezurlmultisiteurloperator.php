<?php
/**
 * File containing the eZURLMultisiteURLOperator class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2 (or any later version)
 * @package ezurlmultisite
 */

/**
 * The kernel's eZURLOperator with a multisite-aware ezurl.
 *
 * Registered by this extension's eztemplateautoload.php for every operator
 * name of the kernel class, so the template engine never creates the kernel
 * object next to this one (it registers an object for all the names of its
 * operatorList() the first time one of them runs). No kernel override and no
 * EZP_AUTOLOAD_ALLOW_KERNEL_OVERRIDE are needed.
 *
 * Only ezurl changes: a link to content of another participating siteaccess
 * becomes that siteaccess' address (eZURLMultisite::crossSiteURL()), an
 * optional third parameter hash selects the extended options of
 * eZURLMultisite::buildURL(), and everything else is the kernel's code.
 */
class eZURLMultisiteURLOperator extends eZURLOperator
{
    public function operatorTemplateHints()
    {
        $hints = parent::operatorTemplateHints();
        // ezurl is decided at run time: which siteaccess a link belongs to
        // depends on the siteaccess the compiled template is shown in.
        if ( isset( $hints[$this->URLName] ) )
        {
            $hints[$this->URLName]['element-transformation'] = false;
            unset( $hints[$this->URLName]['element-transformation-func'] );
        }
        return $hints;
    }

    public function namedParameterList()
    {
        $list = parent::namedParameterList();
        $list['parameters'] = array( 'type' => 'array',
                                     'required' => false,
                                     'default' => array() );
        return $list;
    }

    public function modify( $tpl, $operatorName, $operatorParameters, $rootNamespace, $currentNamespace, &$operatorValue, $namedParameters, $placement )
    {
        if ( $operatorName === $this->URLName )
        {
            if ( ( !empty( $namedParameters['parameters'] ) && is_array( $namedParameters['parameters'] ) ) ||
                 eZURLMultisite::ini()->variable( 'OperatorSettings', 'ForceAbsoluteURL' ) === 'enabled' )
            {
                $operatorValue = eZURLMultisite::buildURL( $operatorValue, $namedParameters, 'ezurl' );
                return;
            }

            $url = eZURLMultisite::crossSiteURL( $operatorValue, isset( $namedParameters['server_url'] ) ? $namedParameters['server_url'] : 'relative' );
            if ( $url !== false )
            {
                $operatorValue = $url;
                $http = eZHTTPTool::instance();
                if ( isset( $http->UseFullUrl ) && $http->UseFullUrl && strncasecmp( $operatorValue, '/', 1 ) === 0 )
                {
                    $operatorValue = $http->createRedirectUrl( $operatorValue, array( 'pre_url' => false ) );
                }
                $quote = isset( $namedParameters['quote_val'] ) ? $namedParameters['quote_val'] : 'double';
                $operatorValue = eZURLMultisite::applyQuotes( $operatorValue, $quote === 'single' || $quote === 'no' ? $quote : 'double' );
                return;
            }
        }

        parent::modify( $tpl, $operatorName, $operatorParameters, $rootNamespace, $currentNamespace, $operatorValue, $namedParameters, $placement );
    }
}

?>
