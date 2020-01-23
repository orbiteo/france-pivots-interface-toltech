<?php
require_once(dirname(__FILE__).'/../../config/config.inc.php');
define('DEBUG', true);
define('PS_SHOP_PATH', 'https://france-pivots.com/magasin/');
define('PS_WS_AUTH_KEY', 'WWKPQP4PSQ4LJWGY1BLYQJNI6LIQ6AWC');
require_once('./PSWebServiceLibrary.php');


/*** DÉBUT EXTRACT ORDERS ***/
$dateDeLUpdate = date('Y-m-d');
$myFileOrders = _PS_MODULE_DIR_.'/interfaceerp/exports/orders/orders'.$dateDeLUpdate.'.csv';
$fhOrders = fopen($myFileOrders, 'w') or die("impossible de créer le fichier");
//Inscrire les en-tete du fichier csv
$enteteOrders = ["Type", "ID Client", "Ref Commande", "Reference Produit", "Quantite", "Date de commande"];
fputcsv($fhOrders, $enteteOrders);

$orderReference = "";

$arrayORDR = []; //Tableau recap de toutes les commandes

//sortir le xml orders
$webService = new PrestaShopWebservice(PS_SHOP_PATH, PS_WS_AUTH_KEY, DEBUG);
try {
  $xml = $webService->get(array('resource' => 'orders'));
  $totalOrders = $xml->orders->children();
  //Faire une boucle pour sortir tous les id
  foreach ($totalOrders as $order) {
    $idOrder = $order->attributes();
    // faire un appel API avec chaque id
    $xml = $webService->get(array('url' => PS_SHOP_PATH.'/api/orders/'.$idOrder));
    $orderDetails = $xml->children()->children();
    // extraire les données dans des variables puis dans un tableau par id, puis dans un tableau de tous les id
    $orderId = $orderDetails->id;

    if($orderDetails->current_state != "5" && $orderDetails->current_state != "6" && $orderDetails->current_state != "8") { // si l'état de la commande n'est ni livrée (5), ni annulée (6), ni en erreur de paiement (8) on remplit le tableau:
      $orderCustomerId = $orderDetails->id_customer;
      $orderReference = $orderDetails->reference;
      $orderDate = $orderDetails->date_add;
      $orderIdsProducts = "";
      $orderQuantities = "";

      $productsDetails = $orderDetails->associations->children()->children();
      foreach ($productsDetails as $key) {
        $quantityPerId = $key->product_quantity;
        $productPrice = $key->product_price;
        $productReference = $key->product_reference;

        $arrayDetailsOrder = array("L", "", $orderReference, $productReference, $quantityPerId, "");
        array_push($arrayORDR, $arrayDetailsOrder);
      }
      try {
        $xml3 = $webService->get(array('url' => PS_SHOP_PATH.'/api/customers/'.$orderCustomerId));
        $customerDetail = $xml3->children()->children();
        $idCustomerDetail = $customerDetail->id;
      }
      catch (PrestaShopWebserviceException $e) {
        $trace = $e->getTrace();
        if ($trace[0]['args'][0] == 404) { //Si id client inexistant 
          $idCustomerDetail = "";
          $orderCustomerName = "";
          $customerEmail = "";
          $customerSiret = "";
        }
        else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
        else echo $e->getMessage();
      }
        
      //}
      $arrayEnteteOrder = array("E", $idCustomerDetail, $orderReference, "", "", $orderDate);
      array_push($arrayORDR, $arrayEnteteOrder); // ajouter chaque tableau client au tableau général
    }
  }
  // remplir le fichier csv avec ces données avec la méthode fputcsv()
  foreach ($arrayORDR as $fields) {
    fputcsv($fhOrders, $fields);
  }
  fclose($fhOrders);
}
catch (PrestaShopWebserviceException $e) {
    $trace = $e->getTrace();
    if ($trace[0]['args'][0] == 404) echo 'Bad ID';
    else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
    else echo $e->getMessage();
}
/*** / FIN EXTRACT ORDERS ***/
