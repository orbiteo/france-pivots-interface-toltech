<?php
require_once(dirname(__FILE__).'/../../config/config.inc.php');
define('DEBUG', true);
define('PS_SHOP_PATH', 'https://france-pivots.com/magasin/');
define('PS_WS_AUTH_KEY', 'WWKPQP4PSQ4LJWGY1BLYQJNI6LIQ6AWC');
require_once('./PSWebServiceLibrary.php');


$arrayFichesProduit = [];
// Sortir la date du jour et vérifier si un fichier de maj produit est dispo à cette date
$dateOFD = date("d-m-Y");
if (($handle = fopen($dateOFD."_products_import.csv", "r")) !== FALSE) { // Import du fichier .csv
  while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
    if($data[2] === "p" && is_numeric($data[0]) && $data[3] != "NULL") { //On vérifier que la colonne id_product soit un int et qu'il s'agit d'un produit
        array_push($arrayFichesProduit, $data);
    }
  }
  /*** APPEL API PRESTASHOP ***/
  $webService = new PrestaShopWebservice(PS_SHOP_PATH, PS_WS_AUTH_KEY, DEBUG);
  for($i=0 ; $i<count($arrayFichesProduit) ; $i++){
    // Création du link_rewrite sans accent, espace, etc...
    $unwanted_array = array('Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
    'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U',
    'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c',
    'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
    'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y' );
    $link_rewriteSansAccent = strtr($arrayFichesProduit[$i][4], $unwanted_array);
    $link_rewriteSansEspace = strtr($link_rewriteSansAccent, ' ', '-');
    $link_rewriteSansApost = strtr($link_rewriteSansEspace, "'", '-');
    $link_rewriteSansPoint = strtr($link_rewriteSansApost, ".", '-');
    $link_rewriteSansVirgule = strtr($link_rewriteSansPoint, ",", '-');
    $link_rewriteSansSlach = strtr($link_rewriteSansVirgule, "/", '-');
    $link_rewriteSansPar1 = strtr($link_rewriteSansSlach, "(", '-');
    $link_rewriteSansPar2 = strtr($link_rewriteSansPar1, ")", '-');
    $link_rewriteMinuscules = strtolower($link_rewriteSansPar2);
    $nameSansApos = strtr($link_rewriteSansAccent,  "'", '-');
    $nameSansAposMin = strtolower($nameSansApos);
    $nbDeCarateres = strlen($link_rewriteMinuscules);
    if($nbDeCarateres >= 120) {
      $nameMax128 = substr($link_rewriteMinuscules, 0, 120);
    } else {
      $nameMax128 = $link_rewriteMinuscules;
    }
      try { // Appel de l'API avec un id produit
        //Mise à jour des produits existants
        $xml = $webService->get(array('url' => PS_SHOP_PATH.'/api/products/'.$arrayFichesProduit[$i][3])); // On va sortir chaque fiche produit
        //récupération node product
        $product = $xml->children()->children();
        // Nodes obligatoires
        $product->id = (int)$arrayFichesProduit[$i][3];
        $product->price = floatval($arrayFichesProduit[$i][5]);
        $product->link_rewrite->language[0][0] = $nameMax128;
        unset($xml->children()->children()->manufacturer_name);
        unset($xml->children()->children()->quantity);

        //Envoi des données
        $opt = array('resource' => 'products');
        $opt['putXml'] = $xml->asXML(); // Put pour modifier et id obligatoire
        $opt['id'] = (int)$arrayFichesProduit[$i][3]; //Obligatoire
        $xml = $webService->edit($opt); //Edit
        
        //Modification des quantités associées à cet id_product
        $xml = $webService->get($opt);
        foreach ($xml->product->associations->stock_availables->stock_available as $stock) {
            $xml2 = $webService->get(array('url' => PS_SHOP_PATH.'/api/stock_availables?schema=blank'));
            $stock_availables = $xml2->children()->children();
            $stock_availables->id = $stock->id;
            $stock_availables->id_product  = (int)$arrayFichesProduit[$i][3];
            $stock_availables->quantity = floatval($arrayFichesProduit[$i][6]);
            $stock_availables->id_shop = 1;
            $stock_availables->out_of_stock = 1;
            $stock_availables->depends_on_stock = 0;
            $stock_availables->id_product_attribute = $stock->id_product_attribute;

            //POST des données vers la ressource 
            $opt = array('resource' => 'stock_availables');
            $opt['putXml'] = $xml2->asXML();
            $opt['id'] = $stock->id ;
            $xml2 = $webService->edit($opt);
        }
      }
      catch (PrestaShopWebserviceException $e) { // Si id produit non existant => erreur donc création
        $trace = $e->getTrace();
        if ($trace[0]['args'][0] == 404) { // Sinon on le créé le produit
          try {
            $xml = $webService->get(array('url' => PS_SHOP_PATH.'/api/products?schema=blank'));
            //récupération node category
            $product = $xml->children()->children();
            // Nodes obligatoires
            $product->price = floatval($arrayFichesProduit[$i][5]);
            $product->name->language[0][0] = $link_rewriteSansAccent;
            $product->link_rewrite->language[0][0] = $link_rewriteMinuscules;
            $product->link_rewrite->language[0][0]['id'] = 2;
            $product->link_rewrite->language[0][0]['xlink:href'] = PS_SHOP_PATH . '/api/languages/' . 2;

            //Envoie des données
            $opt = array('resource' => 'products');
            $opt['postXml'] = $xml->asXML(); //post pour créer
            $xml = $webService->add($opt); //Add
            $ps_product_id = $xml->product->id;

            //Modification des quantités associées à cet id_product
            $xml = $webService->get($opt);
            foreach ($xml->product->associations->stock_availables->stock_available as $stock) {
                $xml2 = $webService->get(array('url' => PS_SHOP_PATH.'/api/stock_availables?schema=blank'));
                $stock_availables = $xml2->children()->children();
                $stock_availables->id_product  = $ps_product_id;
                $stock_availables->quantity = floatval($arrayFichesProduit[$i][6]);
                $stock_availables->id_shop = 1;
                $stock_availables->out_of_stock = 1;
                $stock_availables->depends_on_stock = 0;
                $stock_availables->id_product_attribute = $stock->id_product_attribute;

                //POST des données vers la ressource 
                $opt = array('resource' => 'stock_availables');
                $opt['putXml'] = $xml2->asXML();
                $xml2 = $webService->add($opt);
            }
          }
          catch (PrestaShopWebserviceException $e) {
              $trace = $e->getTrace();
              if ($trace[0]['args'][0] == 404) echo 'Bad ID';
              else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
              else echo $e->getMessage();
          }
        }
        else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
        else echo $e->getMessage();
      }
    }
        /*** FIN APPEL API PRESTASHOP ***/
}
else {
  exit();
}
