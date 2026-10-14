<?php
/**
 * Template operators of ezurlmultisite.
 *
 * eZURLMultisiteURLOperator takes over every name of the kernel's
 * eZURLOperator (kernel/common/eztemplateautoload.php). Extension autoloads
 * are read after the kernel's, so these definitions win; all ten names must
 * stay listed, or the kernel object is created for the missing one and takes
 * ezurl back for the rest of the request.
 */

$eZTemplateOperatorArray = array();

$eZTemplateOperatorArray[] = array(
    'script' => 'extension/ezurlmultisite/classes/ezurlmultisiteurloperator.php',
    'class' => 'eZURLMultisiteURLOperator',
    'operator_names' => array( 'ezurl', 'ezroot', 'ezdesign', 'ezimage', 'exturl',
                               'ezsys', 'ezhttp', 'ezhttp_hasvariable', 'ezini', 'ezini_hasvariable' )
);

$eZTemplateOperatorArray[] = array(
    'script' => 'extension/ezurlmultisite/classes/ezurlmultisiteoperator.php',
    'class' => 'eZURLMultisiteOperator',
    'operator_names' => array( 'ezurl_path', 'ezurl_siteaccess', 'ezurl_roots' )
);

?>
