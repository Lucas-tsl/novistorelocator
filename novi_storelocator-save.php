<?php
/*
Backup technique de plugin (ne pas activer dans WordPress).
*/

define('NOVI_JSON_DIR', plugin_dir_path(__FILE__) . 'assets/json/');

function getPluginPath(){
    $plugin_file_path = __FILE__;
    $plugin_dir_path = dirname($plugin_file_path);
    
    //var_dump($plugin_dir_path);

    $plugin_path_pend = explode('/public', $plugin_dir_path, 2);
    $plugin_path = $plugin_path_pend[1]."/";

    return $plugin_path;
}

function getPluginPathServer(){
    $plugin_file_path = __FILE__;
    $plugin_dir_path = dirname($plugin_file_path);
    
    //var_dump($plugin_dir_path);

    $plugin_path_pend = explode('/public', $plugin_dir_path, 2);
    $plugin_path = $plugin_path_pend[1]."/";

    return $plugin_path;
}

$plugin_path = getPluginPath();

//var_dump($plugin_path);

//echo "curdir: ".getcwd();

function enqueue_storelocator_script() {
    if (is_admin()) {
        return;
    }

    if (!is_singular()) {
        return;
    }

    global $post;
    if (!$post || !has_shortcode($post->post_content, 'store_locator')) {
        return;
    }

    $plugin_path = plugin_dir_url(__FILE__);
    $settings = updData();
    if (!is_array($settings)) {
        $settings = array();
    }

    $settings = wp_parse_args($settings, array(
        'apikey' => '',
        'btncolor' => '',
        'btncolorbg' => ''
    ));

    $script_version = filemtime(plugin_dir_path(__FILE__) . 'assets/js/storelocator.js');

    // Enqueue votre script JavaScript
    wp_enqueue_script('storelocator', $plugin_path . 'assets/js/storelocator.js', array(), $script_version, true);
    wp_localize_script('storelocator', 'storelocator_vars', array(
        'plugin_url' => $plugin_path,
        'settings' => $settings,
    ));

    // Ajoutez l'attribut type="module" au script
    add_filter('script_loader_tag', function($tag, $handle, $src) {
        if ($handle === 'storelocator') {
            $tag = '<script type="module" src="' . esc_url($src) . '"></script>';
        }
        return $tag;
    }, 10, 3);
}
add_action('wp_enqueue_scripts', 'enqueue_storelocator_script');



// Fonction qui génère le HTML à insérer avec le shortcode
function shortcode_html() {

    $plugin_path = getPluginPath();

    //$plugin_path = substr($plugin_path_pend[1], 0, -1);

    //$plugin_path = $plugin_dir_path;

    //$plugin_path = $plugin_path_pend[1];
    // Remplacez ceci par le HTML que vous souhaitez afficher
    $html_content = "<link rel='stylesheet' href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css' integrity='sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=' crossorigin=''><script src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js' integrity='sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=' crossorigin=''></script><link rel='stylesheet' href='".$plugin_path . 'assets/css/storelocator.css'."'><div class='store-locator'><style>.single_post_title.text_left{padding:0;} p{margin:0;}</style><div class='main-cont'><div class='search-fields'><input id='searchInput' type='text' placeholder='Entrez votre ville, code postal...' /><div id='searchResults'></div><div id='loadingMessage' style='display: none;'>Recherche en cours...</div></div><div class='map-cont flex'><div id='map'></div><div id='listinfo' class='listinfo'><!--<div class='card' id='card-1' onclick=''><div class='card-title' id='card-title-1'>City Name</div><div class='card-subtitle' id='card-subtitle-1'>Ligne 5 city</div><a href='' class='btn'>J'Y VAIS</a></div>--></div></div></div></div>";

    return $html_content;
}
// Enregistrement du shortcode
add_shortcode('store_locator', 'shortcode_html');

// Créer une page d'administration pour l'upload CSV
function custom_csv_upload_page() {
    add_menu_page('NOVI Store Locator Uploader', 'NOVI Store Locator Uploader', 'manage_options', 'csv_upload_page', 'csv_upload_page_callback');
    add_submenu_page(
        'csv_upload_page',
        'Paramètres',
        'Paramètres',
        'manage_options',
        'storelocator-page2',
        'storelocator_page2'
    );
}
add_action('admin_menu', 'custom_csv_upload_page');

function arr_to_json($data){

    $plugin_path = getPluginPath();

    $json_directory = "..".$plugin_path . 'assets/json/settings/'; //on est dans public/wp-admin donc on doit revenir en arrière dans public/ pour aller dans wp-content
    $json_filename = 'settings.json';

    // Chemin du fichier JSON à créer
    $chemin_fichier_json = $json_directory.$json_filename;

    // Convertir le tableau en format JSON
    $json_data = json_encode($data);

    // Écrire le contenu JSON dans le fichier
    $resultat = file_put_contents($chemin_fichier_json, $json_data);

    // Vérifiez si l'écriture a réussi ou non
    if ($resultat !== false) {
        echo "<div class='done2'>Les données JSON ont été écrites avec succès dans le fichier.</div>";
    } else {
        // Récupérez les erreurs de dernière erreur PHP
        $error = error_get_last();
    
        // Affichez le message d'erreur
        echo "<div class='err2'>Une erreur est survenue dans l'écriture du fichier : ".$error."</div>";
    }

}

function updData(){

    $plugin_path = getPluginPath();

    $json_directory = plugin_dir_path(__FILE__) . 'assets/json/settings/'; //on est dans public/wp-admin donc on doit revenir en arrière dans public/ pour aller dans wp-content
    $json_filename = 'settings.json';

    // Chemin du fichier JSON à créer
    $chemin_fichier_json = $json_directory.$json_filename;

    // Lire le contenu du fichier JSON
    $json_contenu = file_get_contents($chemin_fichier_json);

    // Décoder le JSON en un tableau associatif
    $data = json_decode($json_contenu, true);

    return $data;

}

if(isset($_POST['submit'])){
    if(isset($_POST['apikey'])){
        if(!empty($_POST['apikey'])){
            $data = array(
                'apikey' => $_POST['apikey'],
                'btncolor' => $_POST['btncolor'],
                'btncolorbg' => $_POST['btncolorbg']
            );
            arr_to_json($data);
        }
    }

}

// Callback pour afficher le formulaire d'upload
function csv_upload_page_callback() {

    $plugin_path = getPluginPath();

    $json_directory = "..".$plugin_path . 'assets/json/'; //on est dans public/wp-admin donc on doit revenir en arrière dans public/ pour aller dans wp-content
    $old_json_filename = 'old_stores.json';
    $new_json_filename = 'stores.json';

    // Vérifier si le fichier "old_stores.json" existe pour griser le bouton
    $is_old_stores_exist = file_exists($json_directory . $old_json_filename);


    ?>
    <style>
        .err{
            margin: 30 0 0 183px;
            background:#cf635b;
            color:#fff;
            padding:5px 10px;
            width: calc(100% - 220px);
        }
        .done{
            margin: 30 0 0 183px;
            background:#6acf5b;
            color:#fff;
            padding:5px 10px;
            width: calc(100% - 220px);
        }
    </style>
    <div class="wrap">
        <h2>NOVI Store Locator Uploader</h2>
        <p>
            Rendez-vous sur le fichier Google Sheet des magasins. <br>
            Effectuez vos ajouts/modifications<br>
            /!\ Ne touchez jamais à la 1ère ligne <br>
            Une fois vos modifications terminées, faites : fichier > Télécharger > Valeurs séparées par des virgules (CSV)<br>
            Puis, sur cette page, cliquez sur le bouton "Choisir un fichier", puis sélectionnez le fichier que vous venez de télécharger.<br>
            Puis, appuyez sur "Upload"<br>
            Patientez. La conversion peut prendre plusieurs minutes.<br><br>
            <br>
            Pour intégrer le store locator, vous n'avez plus qu'à créer une nouvelle page, et y insérer le short code suivant : [store_locator]
        </p>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="csv_file" />
            <?php wp_nonce_field('csv_upload_nonce', 'csv_upload_nonce'); ?>
            <input type="submit" name="submit" value="Upload" />
            <?php if ($is_old_stores_exist) : ?>
                <input type="submit" name="revert" value="Revenir à la version précédente" onclick="return confirm('Êtes-vous sûr de vouloir revenir à la version précédente ?');" />
            <?php else : ?>
                <input type="submit" name="revert" value="Revenir à la version précédente" disabled="disabled" />
            <?php endif; ?>
        </form>

        <!--<div class="spacer" style="border-top:1px solid gray; width:100%; height:2px; margin: 20px 0;"></div>-->
    </div>
    <?php
}

// Gérer l'upload du fichier CSV et sa conversion en JSON
function handle_csv_upload() {

    $plugin_path = getPluginPath();

    $json_directory = "..".$plugin_path . 'assets/json/'; //on est dans public/wp-admin donc on doit revenir en arrière dans public/ pour aller dans wp-content
    $old_json_filename = 'old_stores.json';
    $new_json_filename = 'stores.json';
    
    if (isset($_POST['submit'])) {
        if (!isset($_FILES['csv_file'])) {
            echo '<div class="err">Fichier manquant</div>';
            return;
        }

        $file_name = $_FILES['csv_file']['name'];
        $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);

        // Vérifier si le fichier est au format CSV
        if (strtolower($file_ext) !== 'csv') {
            echo '<div class="err">Le fichier n\'est pas au format CSV</div>';
            return;
        }

        if (!wp_verify_nonce($_POST['csv_upload_nonce'], 'csv_upload_nonce')) {
            wp_die('Security check');
        }

        $uploaded_file = $_FILES['csv_file'];

        $upload_overrides = array('test_form' => false);
        $movefile = wp_handle_upload($uploaded_file, $upload_overrides);

        if ($movefile && empty($movefile['error'])) {
            // Convertir le fichier CSV en JSON
            $csv_file = $movefile['file'];
            $json_data = convert_csv_to_json($csv_file);

            // Vérifier si le fichier "stores.json" existe et le renommer en "old_stores.json"
            if (file_exists($json_directory . $new_json_filename)) {
                rename($json_directory . $new_json_filename, $json_directory . $old_json_filename);
            }

            // Enregistrer le nouveau fichier JSON
            file_put_contents($json_directory . $new_json_filename, $json_data);

            echo '<div class="done">Le fichier CSV a été uploadé et converti en JSON avec succès. </div>';
        } else {
            echo '<div class="err">Erreur lors de l\'upload du fichier : ' . $movefile['error'] . '</div>';
        }
    }
        // Gérer la suppression et le renommage des fichiers JSON
        if (isset($_POST['revert'])) {

            if (file_exists($json_directory . $new_json_filename)) {
                unlink($json_directory . $new_json_filename);
            }
            if (file_exists($json_directory . $old_json_filename)) {
                rename($json_directory . $old_json_filename, $json_directory . $new_json_filename);
                echo '<div class="done">La version précédente à été restaurée avec succès.</div>';
            } else {
                echo '<div class="err">Le fichier old_stores.json n\'existe pas, restauration de la sauvegarde impossible.</div>';
            }
        }
}


add_action('admin_init', 'handle_csv_upload');

// Fonction pour convertir le fichier CSV en JSON
function convert_csv_to_json($csv_file) {
    $file_handle = fopen($csv_file, 'r');
    $json_data = array();

    if ($file_handle) {
        $headers = fgetcsv($file_handle); // Récupérer la première ligne comme en-têtes

        while (($data = fgetcsv($file_handle)) !== false) {
            if (count($data) !== count($headers)) {
    continue; // Ignorer les lignes mal formatées
}
            $entry = array();
            foreach ($headers as $index => $header) {
                $entry[$header] = $data[$index];
            }
            $json_data[] = $entry;
        }

        fclose($file_handle);

        return json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        return false;
    }
}




function storelocator_page2() {
    // Contenu de la deuxième page
    echo '<h1>Modifier paramètres</h1>';

    $data = updData()

    ?>

    <style>
        .err2{
            margin: 30 0 0 183px;
            background:#cf635b;
            color:#fff;
            padding:5px 10px;
            width: calc(100% - 220px);
        }
        .done2{
            margin: 30 0 0 183px;
            background:#6acf5b;
            color:#fff;
            padding:5px 10px;
            width: calc(100% - 220px);
        }
    </style>

    <form method="post" action="">

        <table class="form-table">
            <tr valign="top">
                <th scope="row">Clé API MapTiler :</th>
                <td>
                    <input type="text" name="apikey" value="<?php echo $data['apikey']; ?>" />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Couleur texte des boutons : (hexadecimal)</th>
                <td>
                    <input type="text" name="btncolor" value="<?php echo $data['btncolor']; ?>" placeholder="ex: #ff0000" />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Couleur fond des boutons : (hexadecimal)</th>
                <td>
                    <input type="text" name="btncolorbg" value="<?php echo $data['btncolorbg']; ?>" placeholder="ex: #ff0000" />
                </td>
            </tr>
        </table>

        <input type="submit" name="submit" value="Enregistrer" class="button button-primary" />
    </form>


    <?php
}
