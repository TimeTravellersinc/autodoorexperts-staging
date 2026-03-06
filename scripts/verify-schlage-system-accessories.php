<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$bootstrap = [dirname(__DIR__) . '/site/wp-load.php', '/var/www/html/wp-load.php'];
$loaded=false; foreach($bootstrap as $c){ if(is_file($c)){ require_once $c; $loaded=true; break; } }
if(!$loaded || !function_exists('wc_get_product_id_by_sku')) exit(1);
$checks = [
 ['sku'=>'SCH-700-SERIES-PUSHBUTTONS','brand'=>'schlage','cat'=>'actuators'],
 ['sku'=>'SCH-620-631-SERIES-PUSHBUTTONS','brand'=>'schlage','cat'=>'actuators'],
 ['sku'=>'SCH-672-SERIES-TOUCHBAR','brand'=>'schlage','cat'=>'actuators'],
 ['sku'=>'SCH-692-SERIES-SMARTBAR','brand'=>'schlage','cat'=>'actuators'],
 ['sku'=>'SCH-650-SERIES-KEYSWITCHES','brand'=>'schlage','cat'=>'keyswitches'],
 ['sku'=>'SCH-FSS1-SERIES-DOOR-SENSORS','brand'=>'schlage','cat'=>'sensors'],
 ['sku'=>'SCH-674-679-7764-7766-DPS','brand'=>'schlage','cat'=>'sensors'],
 ['sku'=>'SCH-SCAN-II-MOTION-SENSORS','brand'=>'schlage','cat'=>'sensors'],
 ['sku'=>'SCH-1910-SERIES-ELECTRIC-HORNS','brand'=>'schlage','cat'=>'miscellaneous'],
 ['sku'=>'SCH-740-SERIES-BREAK-GLASS','brand'=>'schlage','cat'=>'miscellaneous'],
 ['sku'=>'SCH-660-SERIES-REMOTE-RELEASE','brand'=>'schlage','cat'=>'miscellaneous'],
 ['sku'=>'SCH-800-801-MONITORING-STATIONS','brand'=>'schlage','cat'=>'miscellaneous'],
 ['sku'=>'SCH-8200-REMOTE-DESK-CONSOLE','brand'=>'schlage','cat'=>'miscellaneous'],
 ['sku'=>'SCH-442S-CABINET-LOCK','brand'=>'schlage','cat'=>'miscellaneous'],
 ['sku'=>'SCH-PB405-SERIES-POWER-BOLT','brand'=>'schlage','cat'=>'miscellaneous'],
];
$fail=0; foreach($checks as $row){ $id=(int)wc_get_product_id_by_sku($row['sku']); if($id<=0){ echo 'FAIL|' . $row['sku'] . '|missing' . PHP_EOL; $fail++; continue; } $p=wc_get_product($id); $brands=wp_get_post_terms($id,'product_brand',['fields'=>'slugs']); $cats=wp_get_post_terms($id,'product_cat',['fields'=>'slugs']); $thumb=(int)get_post_thumbnail_id($id); $price=(string)$p->get_regular_price(); $source=(string)get_post_meta($id,'_ado_import_url',true); $issues=[]; if(!in_array($row['brand'],$brands,true)) $issues[]='brand'; if(!in_array($row['cat'],$cats,true)) $issues[]='category'; if($thumb<=0) $issues[]='image'; if($price===''||(float)$price<=0) $issues[]='price'; if($source==='') $issues[]='source'; if($issues){ echo 'FAIL|' . $row['sku'] . '|' . implode(',',$issues) . PHP_EOL; $fail++; continue; } echo 'OK|' . $row['sku'] . '|' . $id . '|' . $price . PHP_EOL; }
exit($fail>0?1:0);
