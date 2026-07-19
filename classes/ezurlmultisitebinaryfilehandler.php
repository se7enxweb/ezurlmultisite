<?php

class eZURLMultisiteBinaryFileHandler implements eZURLMultisiteDataTypeHandler
{
    public function getURL( $attribute, $linkType, $options = array() )
    {
        if ( !$attribute || !is_object( $attribute ) )
        {
            return false;
        }

        if ( $linkType === 'download' )
        {
            $object = $attribute->object();
            if ( $object )
            {
                return 'content/download/' . $object->attribute( 'id' ) . '/' . $attribute->attribute( 'id' ) . '/' . $attribute->content()->attribute( 'original_filename' );
            }
        }

        $content = $attribute->content();
        if ( is_object( $content ) )
        {
            $filePath = $content->attribute( 'filepath' );
            if ( $filePath )
            {
                return $filePath;
            }
        }

        return false;
    }
}

?>
