<? # -*-php-*-

require_once('phptableau.php');

$db_host     = 'localhost';
$db_user     = 'test';
$db_password = 'test';
$db_database = 'test';

$connection = mysql_connect($db_host,$db_user,$db_password);
mysql_select_db($db_database) or die("Unable to select database");

$tableau = new PhpTableau($connection, 'fubar');

$tableau->set_columns(
    'id',       new IDColumn(),
    'fubar',    new ChoiceColumn(array('a', 'b', 'c')),
    'darkness', new DateTimeColumn(),
    'saab',     new ForeignKeyColumn($connection, 'fubar', 'fubar', 'saab')
    );
$tableau->set_name(
    'id', "ID",
    'fubar', "FUBAR",
    'darkness', "DARKNESS",
    'saab', "Saab"
    );
$tableau->set_comment(
    'id', "Identifier",
    'fubar', "Fubar of foo",
    'darkness', "FOO",
    'saab', "FOO"
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

$tableau->add_validator('fubar', validate_fubar);
$tableau->add_callback('display', color_display);

$tableau->display();
