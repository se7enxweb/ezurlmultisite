<?php

class eZURLMultisiteURLHandler implements eZURLMultisiteDataTypeHandler
{
    public function getURL( $attribute, $linkType, $options = array() )
    {
        if ( !$attribute || !is_object( $attribute ) )
        {
            return false;
        }

        if ( $attribute->hasContent() || $attribute->content() )
        {
            $content = $attribute->content();

            if ( is_object( $content ) && $content instanceof eZURL )
            {
                return $content->url();
            }

            return (string)$content;
        }

        return false;
    }
}

?>
