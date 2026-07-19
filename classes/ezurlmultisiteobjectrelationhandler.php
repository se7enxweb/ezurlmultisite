<?php

class eZURLMultisiteObjectRelationHandler implements eZURLMultisiteDataTypeHandler
{
    public function getURL( $attribute, $linkType, $options = array() )
    {
        if ( !$attribute || !is_object( $attribute ) )
        {
            return false;
        }

        $relatedObject = $attribute->content();
        if ( is_object( $relatedObject ) && $relatedObject instanceof eZContentObject )
        {
            $mainNode = $relatedObject->mainNode();
            if ( $mainNode )
            {
                return $mainNode->attribute( 'url_alias' );
            }
        }

        return false;
    }
}

?>
