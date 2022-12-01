<?php

$servername = "***";
$username = "***";
$password = "***";
$dbname = "***";

$user_onliner = '***';
$pass_onliner = '***';

function prenty($data) {
  echo '<pre>';
  print_r($data);
  echo '</pre>';
}

function date_translate($date) {
  $month_rus = array('января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря');
  $month_numb = array('01','02','03','04','05','06','07','08','09','10','11','12');
 
  return str_replace(' ', '-', str_replace($month_rus, $month_numb, $date));
}

$connect = mysqli_connect($servername, $username, $password, $dbname);
$connect->set_charset('utf8');

$url = urlencode('report?start=20220501&end=20220831&filter=order_prime_charge_off');
$page_url = 'https://b2b.onliner.by/login?redirect=' . $url;

$ch = curl_init();
curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:38.0) Gecko/20100101 Firefox/38.0");   
curl_setopt($ch, CURLOPT_COOKIEJAR, str_replace("\\", "/", getcwd()).'/gearbest.txt'); 
curl_setopt($ch, CURLOPT_COOKIEFILE, str_replace("\\", "/", getcwd()).'/gearbest.txt'); 
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); 
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); 
curl_setopt($ch, CURLOPT_URL, $page_url);
curl_setopt($ch, CURLOPT_POSTFIELDS,  array('email' => $user_onliner, 'password' => $pass_onliner));  
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$response['html'] = curl_exec($ch);
$info = curl_getinfo($ch);
if($info['http_code'] != 200 && $info['http_code'] != 404) {
  $error_page[] = array(1, $page_url, $info['http_code']);
}
$response['code'] = $info['http_code'];
$response['errors'] = $error_page;
curl_close($ch);

preg_match_all('#<table class=\"table\">(.+)<div class=\"textmain#su', $response['html'], $orders_table);
preg_match_all('#<tr[^>]*?>(.*?)<\/tr>#su', $orders_table[0][0], $orders_arr);

$arr = array();

$text = '<html>
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <title>(новый заказ) ONLINER PRIME</title>
  <style>
    table {
			border-collapse: collapse;
		}
	
		th, td {
			border: 1px solid grey;
			padding: 5px;
		}
  </style>
</head>
<body>
<table>
<thead>
  <tr>
    <th>Дата создания</th>
    <th>Номер заказа</th>
    <th>Категория</th>
    <th>Наименование</th>
    <th>Кол-во</th>
    <th>Цена продажи</th>
    <th>Комиссия</th>
  </tr>
</thead>
<tbody>
';

foreach($orders_arr[0] as $result) {
  preg_match_all('#Станкоград - (.+)<#u', $result, $number_order);
  
  $query_number = mysqli_query($connect, "SELECT id, number_order FROM prime_orders WHERE number_order = '" . $number_order[1][0] . "'");
  if(!mysqli_fetch_assoc($query_number)) {

    preg_match_all('#<span class=\"creationDate\"><nobr>(.+)<\/nobr><\/span>#su', $result, $creation_date);
    preg_match_all('#\[(.+)\] [A-zА-я]#u', $result, $category_name);
    $price_key = 0;
    
    foreach($category_name[1] as $val_key => $val_name) {
      
      preg_match_all('#\](.+)\((.+ шт.)\)#u', $result, $product_name);
      preg_match_all('#— [0-9\.]+#u', $result, $price_total);
         
      array_push($arr, array(
        'creation_date' => date("d-m-Y", strtotime(date_translate($creation_date[1][0]))),
        'number_order'  => mb_strtoupper($number_order[1][0]),
        'category_name' => $val_name,
        'product_name'  => $product_name[1][$val_key],
        'quantity'      => str_replace(' шт.', '', $product_name[2][$val_key]),
        'price_total'   => str_replace('— ', '', $price_total[0][$price_key]),
        'commission'    => str_replace('— ', '', $price_total[0][$price_key+1]),
        'date_added'    => date("Y-m-d H:i:s")
      ));
      
      $text .= '<tr>
        <td>' . date("Y-m-d H:i:s") . '</td>
        <td>' . mb_strtoupper($number_order[1][0]) . '</td>
        <td>' . $val_name . '</td>
        <td>' . $product_name[1][$val_key] . '</td>
        <td>' . str_replace(' шт.', '', $product_name[2][$val_key]) . '</td>
        <td>' . str_replace('— ', '', $price_total[0][$price_key]) . '</td>
        <td>' . str_replace('— ', '', $price_total[0][$price_key + 1]) . '</td>
      </tr>';
 
      $query ="INSERT INTO prime_orders (creation_date, number_order, category_name, product_name, quantity, price_total, commission, date_added) VALUES ('" . date("Y-m-d", strtotime(date_translate($creation_date[1][0]))) . "', '" . $number_order[1][0] . "', '" . $val_name . "', '" . $product_name[1][$val_key] . "', '" . str_replace(' шт.', '', $product_name[2][$val_key]) . "', '" . str_replace('— ', '', $price_total[0][$price_key]) . "', '" . str_replace('— ', '', $price_total[0][$price_key + 1]) . "', '" . date("Y-m-d H:i:s") . "')";
      mysqli_query($connect, $query);	
      
      $price_key += 2;
    }
  }
}

if($arr) {
  $to       = 'zakazstanko@gmail.com';
  $subject  = '(новый заказ) ONLINER PRIME';
  $message  = $text . '</tbody></table></body></html>';
  $headers  = 'MIME-Version: 1.0' . "\r\n";
  $headers .= "Content-type: text/html; charset=utf-8 \r\n";
  $headers .= 'From: 100@10.by' . "\r\n" .
              'Reply-To: 100@10.by' . "\r\n" .
              'X-Mailer: PHP/' . phpversion();

  mail($to, $subject, $message, $headers);
}

echo 'onliner prime update: success';