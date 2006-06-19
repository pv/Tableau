<? # -*-php-*-

header('Content-Type: text/html; charset=iso-8859-1');

// This is just for timing... Apparently, the time spent in these
// routines is completely negligible compared to the HTTP roundtrip time.
function microtime_float()
{
  list($usec, $sec) = explode(" ", microtime());
  return ((float)$usec + (float)$sec);
} 
$start_time = microtime_float();

/*
 * This file is a test for the Tableau class.
 *
 * Here we provide an interface for two database tables, making it possible
 * for the user to switch from one to another easily.
 */

// First output some HTML, and use a .css file to beautify output
echo <<< __EOF__
<html>
<head>
<link rel="stylesheet" href="Tableau.css">
</head>
<body>
__EOF__;

// Include Tableau
require_once('Tableau.php');

// Ok, specify the database and make a connection
$db_host     = 'localhost';
$db_user     = 'pauli';
$db_password = 'zKxkTosjvt3p9f9e';
$db_database = 'pauli';

$connection = mysql_connect($db_host,$db_user,$db_password);
mysql_select_db($db_database) or die("Unable to select database");


// This will be used below, to validate field values.
// Basically, this function requires that some value is supplied to a field.
function value_required($value, &$msg) {
    if (!$value) {
        $msg = "This field is required.";
        return false;
    }
    return true;
}

// Ok, provide the table selector (very quick'n'dirty)
if (!in_array($_GET['table'], array('staff', 'responsibilities'))) {
    $_GET['table'] = 'staff';
}


$linkbox_other_links = "<span><a href='Tableau.phps'>Tableau.php source</a></span><span><a href='test.phps'>test.php, source for this test</a></span><span><a href='test.sql'>test.sql</a></span>";

if ($_GET['table'] == 'staff') {
    print "<div class='linkbox'><span><b>Staff</b></span> <span><a href=\"?table=responsibilities\">Responsibilities</a></span>$linkbox_other_links</div>";

    // Table selector ended here. Very simple.

    // Ok, then start up the interface.
    // We want now to interface to the table named 'staff'
    $tableau = new Tableau($connection, 'staff');

    // First, we need to specify field names for the columns, and
    // what each column is. This controls how the data is displayed
    // and edited.
    $tableau->set_columns(
        'id',           new Tableau_IDColumn(),
        // IDColumn is a non-editable control, which assumes that 'id'
        // is and auto_incrementing integer in the DB
        'name',         new Tableau_TextColumn(),
        // Ok, plain text
        'birthdate',    new Tableau_DateColumn(),
        // Then, a date
        'phone',        new Tableau_TextColumn(),
        // More text
        'last_updated', new Tableau_LastUpdatedColumn()
        // And an automatically updating "last changed" field.
        );

    // Next, set some pretty display names for the columns
    $tableau->set_name(
        'id',           "ID",
        'name',         "Name",
        'birthdate',    "Birth date",
        'phone',        "Phone number",
        'last_updated', "Last updated"
        );

    // And then longer descriptions to show when editing
    $tableau->set_comment(
        'id',         "Identifier",
        'name',       "Name of the person",
        'birthdate',  "Birth date of the person",
        'phone',      "Work phone number extension"
        );

    // Tell that last_updated field should not be visible.
    $tableau->set_visible('last_updated', false);

    // ...and specify some sensible year range for the date selector.
    // The date selector is quite basic and could probably be improved
    // a lot... JavaScript?
    $tableau->columns['birthdate']->set_year_range(date('Y') - 120,
                                                   date('Y') - 15);

    // Now, use the validation function we defined above.
    // We specify that it should handle fields 'name' and 'birthdate'.
    $tableau->add_validator('name', value_required);
    $tableau->add_validator('birthdate', value_required);

    // Then, we specify a display filter function, that adds
    // "missing"-links to fields whose values are missing.
    function mark_missing($row, $field, &$disp, &$cell_attr) {
        // Note that $field == null indicates the first column,
        // which contains an 'edit row' link
        if ($field and !$row[$field]) {
            $disp = "<a href=\"?action=edit&id=".$row['id']."\">(missing)</a>";
            $cell_attr['style'] = 'background-color: red; color: white;';
        }
    }
    $tableau->add_callback('display', mark_missing);

    // Indicate that we want to sort according to name, and in
    // ascending order
    $tableau->set_default_sort('name', 0);

    // We are now set and done, let's display the table component
    $tableau->display();

} else if ($_GET['table'] == 'responsibilities') {
    print "<div class='linkbox'><span><a href=\"?table=staff\">Staff</a></span> <span><b>Responsibilities</b></span>$linkbox_other_links</div>";

    // This is the specification for the second table,
    // which goes quite in the same way as previously.

    $tableau = new Tableau($connection, 'responsibilities');
    $tableau->set_columns(
        'id', new Tableau_IDColumn(),
        'name', new Tableau_ForeignKeyColumn(
            $connection, 'staff','name','name'),
        // This specifies that possible values for 'name' column are
        // taken from table 'staff', using column 'name' as both the real
        // and shown value.
        'responsibility', new Tableau_ChoiceColumn(
            array('watering plants', 'making fires', 'calling fire brigade'))
        // This specifies that responsibility has three possible values
        // (actually four, including empty)
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

    $tableau->set_default_sort('name');

    // We set a default filter that shows only names containing 'c'.
    // The user can override this either by specifying her own filter,
    // or by hitting the 'Clear' button.
    $tableau->set_default_filters(array(array('name', 'LIKE', 'c')));

    // Ok, display it.
    $tableau->display();
}

$end_time = microtime_float();

print "<span style='float: right; color:#aaa; font-size: 50%;'>Elapsed server time: " . ($end_time - $start_time) . " s</span>";

// And finally print the rest of the HTML
echo <<<__EOF__
</body>
</html>
__EOF__;
