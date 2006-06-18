<? # -*-php-*-

require_once('phptableau.php');

$db_host     = 'localhost';
$db_user     = 'test';
$db_password = 'test';
$db_database = 'test';

$connection = mysql_connect($db_host,$db_user,$db_password);
mysql_select_db($db_database) or die("Unable to select database");

$tableau = new PhpTableau($connection, 'fubar', "utf-8");

$columns = array(
    'id'       => new IDColumn("ID", "Identifier"),
    'fubar'    => new TextColumn("Fubar", "Fubar for all interested"),
    'darkness' => new DateTimeColumn("Darkness", "Darkness unless finished"),
    'saab'     => new TextColumn("Saab", "Saab for the German"),
    );

function validate_fubar($value, &$msg) {
    if ($value != "fubar") {
        return true;
    } else {
        $msg = "Fubar cannot be \"fubar\"";
        return false;
    }
}

function color_display($row, $field, &$disp, &$cell_attr) {
    if ($field == 'saab' and !$row[$field]) {
        $disp = "<a href=\"?action=edit&id=".$row['id']."\">(missing)</a>";
    }
}

$columns['fubar']->add_validator(validate_fubar);

$tableau->add_callback('display', color_display);

$tableau->set_columns($columns);
$tableau->display();
