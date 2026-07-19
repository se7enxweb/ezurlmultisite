<?php

interface eZURLMultisiteDataTypeHandler
{
    /**
     * Returns a URL string for the given content object attribute.
     *
     * @param eZContentObjectAttribute $attribute
     * @param string $linkType internal|external|download
     * @param array $options
     * @return string|false
     */
    public function getURL( $attribute, $linkType, $options = array() );
}

?>
