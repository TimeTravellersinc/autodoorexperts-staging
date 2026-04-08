<?php
$qid=5118;
$s=(string)get_post_meta($qid,'_adq_scoped_json_snapshot',true);
$p=json_decode($s,true);
if(!is_array($p)){echo "invalid\n";exit;}
$meta = is_array($p['meta'] ?? null) ? $p['meta'] : [];
echo 'meta_keys=' . implode(',', array_slice(array_keys($meta),0,40)) . PHP_EOL;
$doors = (array)($p['result']['doors'] ?? []);
echo 'doors=' . count($doors) . PHP_EOL;
if($doors){
  $d = (array)$doors[0];
  echo 'door_keys=' . implode(',', array_keys($d)) . PHP_EOL;
  $items=(array)($d['items'] ?? []);
  echo 'items_count=' . count($items) . PHP_EOL;
  if($items){
    $it=(array)$items[0];
    echo 'item_keys=' . implode(',', array_keys($it)) . PHP_EOL;
    echo 'item_sample=' . wp_json_encode($it, JSON_UNESCAPED_SLASHES) . PHP_EOL;
  }
}
if (!empty($meta['pass2_removed_items']) && is_array($meta['pass2_removed_items'])) {
  echo 'pass2_removed_items_count=' . count($meta['pass2_removed_items']) . PHP_EOL;
  $r=(array)$meta['pass2_removed_items'][0];
  echo 'removed_item_keys=' . implode(',', array_keys($r)) . PHP_EOL;
  echo 'removed_item_sample=' . wp_json_encode($r, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
