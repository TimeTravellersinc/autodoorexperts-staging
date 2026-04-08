<?php
$qid=5118;
$p=json_decode((string)get_post_meta($qid,'_adq_scoped_json_snapshot',true),true);
$meta=is_array($p['meta']??null)?$p['meta']:[];
foreach(array_keys($meta) as $k){ echo $k, PHP_EOL; }
