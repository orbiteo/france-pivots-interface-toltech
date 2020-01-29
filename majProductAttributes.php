<?php
require_once(dirname(__FILE__).'/../../config/config.inc.php');
define('DEBUG', true);
define('PS_SHOP_PATH', 'https://france-pivots.com/magasin/');
define('PS_WS_AUTH_KEY', 'WWKPQP4PSQ4LJWGY1BLYQJNI6LIQ6AWC');
require_once('./PSWebServiceLibrary.php');

$arrayFichesProduit = [];
// Sortir la date du jour et vérifier si un fichier de maj produit est dispo à cette date
$dateOFD = date("d-m-Y");
if (($handle = fopen('imports/products/'.$dateOFD."_products_import.csv", "r")) !== FALSE) { // Import du fichier .csv
  while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
    if($data[2] === "d" && is_numeric($data[0]) && $data[3] != "NULL") { //On vérifier que la colonne id_product soit un int et qu'il s'agit d'une déclinaison
        array_push($arrayFichesProduit, $data);
    } elseif($data[3] == "NULL" && $data[1] != "NULL") { // Si attribute non référencé chez Toltech mais id_product existant, on va créer un faux id dynamiquement pour tomber dans la condition id non existant, donc création
      $SQL = Db::getInstance()->executeS("SELECT MAX(id_product_attribute) AS idMax
      FROM "._DB_PREFIX_."product_attribute"); // retourne l'id le + élevé
      $data[3] = (int)$SQL[0]["idMax"]+1; // id déclinaison 
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
    $nameSansAposMin = strtolower($nameSansApos);
    $nbDeCarateres = strlen($link_rewriteMinuscules);
    if($nbDeCarateres >= 120) {
      $nameMax128 = substr($link_rewriteMinuscules, 0, 120);
    } else {
      $nameMax128 = $link_rewriteMinuscules;
    }
      try { // Appel de l'API avec un id attribute
        //Mise à jour des attributes existants
        $xml = $webService->get(array('url' => PS_SHOP_PATH.'/api/combinations/'.$arrayFichesProduit[$i][3])); // On va sortir chaque fiche produit
        //récupération node product
        $combinations = $xml->children()->children();
        // Vérifier que l'id déclinaison correspond bien à son id produit
        if($combinations->id_product == $arrayFichesProduit[$i][1]) {
            // Nodes obligatoires
            $combinations->id = (int)$arrayFichesProduit[$i][3];
            $combinations->price = floatval($arrayFichesProduit[$i][5]);

            //Envoi des données
            $opt = array('resource' => 'combinations');
            $opt['putXml'] = $xml->asXML(); // Put pour modifier et id obligatoire
            $opt['id'] = (int)$arrayFichesProduit[$i][3]; //Obligatoire
            $xml = $webService->edit($opt); //Edit
            
             //Modification des quantités associées à cet id_product
            $xml = $webService->get(array('url' => PS_SHOP_PATH.'/api/products/'.$arrayFichesProduit[$i][1])); // On va sortir les info de l'id_product correspondant
            foreach ($xml->product->associations->stock_availables->stock_available as $stock) {
                $xml2 = $webService->get(array('url' => PS_SHOP_PATH.'/api/stock_availables?schema=blank'));
                $stock_availables = $xml2->children()->children();
                // chercher l'id_product_attribute correspondant à notre actuel
                if($stock->id_product_attribute == $arrayFichesProduit[$i][3]) {
                    $stock_availables->id = $stock->id;
                    $stock_availables->id_product  = (int)$arrayFichesProduit[$i][1];
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
        }
      }
      catch (PrestaShopWebserviceException $e) { // Si id attribute non existant, on le créé
        $trace = $e->getTrace();
        if ($trace[0]['args'][0] == 404) {
          try {
            $xml = $webService->get(array('url' => PS_SHOP_PATH.'/api/combinations?schema=blank'));
            $combination = $xml->children()->children();
            // Nodes obligatoires
            $combination->id_product = intval($arrayFichesProduit[$i][1]);
            $combination->price = floatval($arrayFichesProduit[$i][5]);
            $combination->location = 0;
            $combination->minimal_quantity = 1;
            $combination->reference = $link_rewriteSansAccent;

            //Envoie des données
            $opt = array('resource' => 'combinations');
            $opt['postXml'] = $xml->asXML(); //post pour créer
            $xml = $webService->add($opt); //Add
            $ps_attribute_id = $xml->combination->id;

            //Créer un id_attribute incrémenté - table product_attribute_combination
            /*Db::getInstance()->insert(_DB_PREFIX_."product_attribute_combination", array(
                'id_product_attribute'  => $ps_attribute_id
            ));
            $SQL = Db::getInstance()->executeS("SELECT MAX(id_attribute) AS idAttriCreated
            FROM "._DB_PREFIX_."product_attribute_combination"); // retourne l'id le + élevé
            $idAttrJustCreated = (int)$SQL[0]["idAttriCreated"]; // id attribute

            //créer ligne sur table attribute_lang avec id_attribute créé + id_lang 1 + name
            Db::getInstance()->insert(_DB_PREFIX_."attribute_lang", array(
              'id_attribute'  => $idAttrJustCreated,
              'id_lang'       => 1,
              'name'          => $link_rewriteSansAccent,
            ));
            */

            //Création des quantités associées à cet id_attribute
            $xml = $webService->get($opt);
            foreach ($xml->product->associations->stock_availables->stock_available as $stock) {
              echo 'toto';
                $xml2 = $webService->get(array('url' => PS_SHOP_PATH.'/api/stock_availables?schema=blank'));
                $stock_availables = $xml2->children()->children();
                $stock_availables->id_product  = intval($arrayFichesProduit[$i][1]);
                $stock_availables->quantity = floatval($arrayFichesProduit[$i][6]);
                $stock_availables->id_shop = 1;
                $stock_availables->out_of_stock = 1;
                $stock_availables->depends_on_stock = 0;
                $stock_availables->id_product_attribute = $ps_attribute_id;

                //POST des données vers la ressource 
                $opt = array('resource' => 'stock_availables');
                $opt['postXml'] = $xml2->asXML();
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
