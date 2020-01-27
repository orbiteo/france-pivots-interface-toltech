<?php
require_once(dirname(__FILE__).'/../../config/config.inc.php');
define('DEBUG', true);
define('PS_SHOP_PATH', 'https://france-pivots.com/magasin/');
define('PS_WS_AUTH_KEY', 'WWKPQP4PSQ4LJWGY1BLYQJNI6LIQ6AWC');
require_once('./PSWebServiceLibrary.php');


/*** EXTRACT FICHIER PRODUITS VERS SAP ***/
//créer fichier .csv
$dateDeLUpdate = date('Y-m-d');
$myFile = _PS_MODULE_DIR_.'/interfaceerp/exports/products/produits'.$dateDeLUpdate.'.csv';
$fh = fopen($myFile, 'w') or die("impossible de créer le fichier");
//Inscrire les en-tete du fichier csv
$entete = ["Product ID", "Produit ou déclinaison", "ID", "Reference", "Quantity"];
fputcsv($fh, $entete);

$arrayDetailsAllProducts = []; //Tableau recap de toutes les commandes

//sortir le xml products
$webService = new PrestaShopWebservice(PS_SHOP_PATH, PS_WS_AUTH_KEY, DEBUG);
try {
  $xml = $webService->get(array('resource' => 'products'));
  $totalproducts = $xml->products->children();
  //Faire une boucle pour sortir tous les id
  foreach ($totalproducts as $product) {
    $idProduct = $product->attributes();
    // faire un appel API avec chaque id
    $xml = $webService->get(array('url' => PS_SHOP_PATH.'/api/products/'.$idProduct));
    $productDetails = $xml->children()->children();
    // extraire les données dans des variables puis dans un tableau par id, puis dans un tableau de tous les id
    $productId = $productDetails->id;
    $productReference = $productDetails->reference;

    $xml2 = $webService->get(array('url' => PS_SHOP_PATH.'/api/stock_availables/'.$productId));
    $productStock = $xml2->children()->children();
    $productQuantity = $productStock->quantity;

    $arrayDetailsProduct = array($productId, "p", $productId, $productReference, $productQuantity); 
    array_push($arrayDetailsAllProducts, $arrayDetailsProduct); // ajouter chaque tableau client au tableau général
  }
  // remplir le fichier csv avec ces données avec la méthode fputcsv()
  foreach ($arrayDetailsAllProducts as $fields) {
    fputcsv($fh, $fields);
  }
}
catch (PrestaShopWebserviceException $e) {
    $trace = $e->getTrace();
    if ($trace[0]['args'][0] == 404) echo 'Bad ID';
    else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
    else echo $e->getMessage();
}

try {
    $xml = $webService->get(array('resource' => 'combinations'));
    $totalCombinations = $xml->combinations->children();
    //Faire une boucle pour sortir tous les id
    foreach ($totalCombinations as $combination) {
      $idCombination = $combination->attributes();
      // faire un appel API avec chaque id
      $xml = $webService->get(array('url' => PS_SHOP_PATH.'/api/combinations/'.$idCombination));
      $combinationDetails = $xml->children()->children();
      // extraire les données dans des variables puis dans un tableau par id, puis dans un tableau de tous les id
      $combinationId = $combinationDetails->id;
      $combinationReference = $combinationDetails->reference;
      $id_product = $combinationDetails->id_product;
  
      $xml = $webService->get(array('url' => PS_SHOP_PATH.'/api/products/'.$id_product)); // On va sortir les info de l'id_product correspondant
      foreach ($xml->product->associations->stock_availables->stock_available as $stock) { // Pour chaque déclinaison du produit
        if(intval($stock->id_product_attribute) === intval($combinationId)) {
            try {
                $xml3 = $webService->get(array('url' => PS_SHOP_PATH.'/api/stock_availables/'.$combinationId));
                $stockAvailableDetails = $xml3->children()->children();
                $combinationQuantity = $stockAvailableDetails->quantity ;

                $arrayDetailsCombination = array($id_product, "d", $combinationId, $combinationReference, $combinationQuantity); 
                array_push($arrayDetailsAllProducts, $arrayDetailsCombination); // ajouter chaque tableau client au tableau général
            }
            catch (PrestaShopWebserviceException $e) {
                $trace = $e->getTrace();
                if ($trace[0]['args'][0] == 404) echo 'Bad ID';
                else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
                else echo $e->getMessage();
            }
        }
      }  
    }
    print_r($arrayDetailsAllProducts);
    // remplir le fichier csv avec ces données avec la méthode fputcsv()
    foreach ($arrayDetailsAllProducts as $fields) {
        fputcsv($fh, $fields);
    }
    fclose($fh);
  }
  catch (PrestaShopWebserviceException $e) {
      $trace = $e->getTrace();
      if ($trace[0]['args'][0] == 404) echo 'Bad ID';
      else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
      else echo $e->getMessage();
  }



/*** FIN EXTRACT FICHIER PRODUITS VERS SAP ***/
