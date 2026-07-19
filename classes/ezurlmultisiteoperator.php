<?php

class eZURLMultisiteOperator
{
    public function __construct()
    {
        $this->Operators = array( 'ezurl_path', 'ezurl_siteaccess', 'ezurl_roots' );
    }

    public function operatorList()
    {
        return $this->Operators;
    }

    public function namedParameterList()
    {
        return array( 'parameters' => array( 'type' => 'array', 'required' => false, 'default' => array() ),
                      'quote_val' => array( 'type' => 'string', 'required' => false, 'default' => 'double' ),
                      'server_url' => array( 'type' => 'string', 'required' => false, 'default' => 'relative' ) );
    }

    public function modify( $tpl, $operatorName, $operatorParameters, $rootNamespace, $currentNamespace, &$operatorValue, $namedParameters, $placement )
    {
        $params = isset( $namedParameters['parameters'] ) ? $namedParameters['parameters'] : array();

        switch ( $operatorName )
        {
            case 'ezurl_path':
            {
                $operatorValue = eZURLMultisite::buildPath( $operatorValue, $namedParameters );
                return;
            }
            case 'ezurl_siteaccess':
            {
                $operatorValue = eZURLMultisite::buildSiteAccessName( $operatorValue, $namedParameters );
                return;
            }
            case 'ezurl_roots':
            {
                $operatorValue = eZURLMultisite::buildRoots();
                return;
            }
        }
    }

    public $Operators;
}

?>
