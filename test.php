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
    'id' => new IDColumn("ID"),
    'fubar' => new TextColumn("Fubar"),
    'darkness' => new TextColumn("Darkness"),
    'saab' => new TextColumn("Saab"),
#    'birthdate' => DateColumn(),
#    'last_updated' => DateTimeColumn(),
    );

function validate_id($value, &$msg) {
    if ($value > 0) {
        return true;
    } else {
        $msg = "FUBLAA!";
        return false;
    }
}

$columns['id']->add_validator(validate_id);
$columns['id']->visible = false;
#$columns['last_update']->editable = false;

$tableau->set_columns($columns);

$tableau->display();
?>
