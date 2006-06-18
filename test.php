<html>
<head>
<link rel="stylesheet" href="phptableau.css">
</head>
<body>
<? # -*-php-*-

require_once('phptableau.php');

$db_host     = 'localhost';
$db_user     = 'test';
$db_password = 'test';
$db_database = 'test';

$connection = mysql_connect($db_host,$db_user,$db_password);
mysql_select_db($db_database) or die("Unable to select database");


function value_required($value, &$msg) {
    if (!$value) {
        $msg = "This field is required.";
        return false;
    }
    return true;
}

if (!in_array($_GET['table'], array('staff', 'responsibilities'))) {
    $_GET['table'] = 'staff';
}


if ($_GET['table'] == 'staff') {
    print "<div class='linkbox'><span><b>Staff</b></span> <span><a href=\"?table=responsibilities\">Responsibilities</a></span></div>";

    $tableau = new PhpTableau($connection, 'staff');
    
    $tableau->set_columns(
        'id',           new IDColumn(),
        'name',         new TextColumn(),
        'birthdate',    new DateColumn(),
        'phone',        new TextColumn(),
        'last_updated', new LastUpdatedColumn()
        );
    $tableau->set_name(
        'id',           "ID",
        'name',         "Name",
        'birthdate',    "Birth date",
        'phone',        "Phone number",
        'last_updated', "Last updated"
        );
    $tableau->set_comment(
        'id',         "Identifier",
        'name',       "Name of the person",
        'birthdate',  "Birth date of the person",
        'phone',      "Work phone number extension"
        );
    $tableau->set_visible('last_updated', false);

    $tableau->columns['birthdate']->set_year_range(date('Y') - 120,
                                                   date('Y') - 15);

    function mark_missing($row, $field, &$disp, &$cell_attr) {
        if ($field and !$row[$field]) {
            $disp = "<a href=\"?action=edit&id=".$row['id']."\">(missing)</a>";
        }
    }
    $tableau->add_callback('display', mark_missing);
    $tableau->add_validator('name', value_required);
    $tableau->add_validator('birthdate', value_required);

    $tableau->display();
} else if ($_GET['table'] == 'responsibilities') {
    print "<div class='linkbox'><span><a href=\"?table=staff\">Staff</a></span> <span><b>Responsibilities</b></span></div>";

    $tableau = new PhpTableau($connection, 'responsibilities');
    $tableau->set_columns(
        'id', new IDColumn(),
        'name', new ForeignKeyColumn($connection,'staff','name','name'),
        'responsibility', new ChoiceColumn(array('watering plants',
                                                 'making fires',
                                                 'calling fire brigade'))
        );
    $tableau->set_name(
        'id', 'ID',
        'name', 'Name',
        'responsibility', 'Responsibility'
        );
    $tableau->set_comment(
        'responsibility', 'What this guy or gal should do?');
    $tableau->add_validator('name', value_required);
    $tableau->add_validator('responsibility', value_required);

    $tableau->display();
}
?>
</body>
</html>