<? # -*-php-*-

require("Net/URL.php");

//---------------------------------------------------------------------------//
// Abstract base for table cell renderers and editors & columns              //
//---------------------------------------------------------------------------//

/**
 * Base class for editors
 */
class Editor
{
    function get_form($prefix, $value) {}
    function get_value($prefix) {}
};

/**
 * Base class for cell displays
 */
class Display
{
    function get($value) {}
};

/**
 * Base class for columns
 */
class Column
{
    var $visible;
    var $editable;
    var $validators;
    var $editor;
    var $display;
    var $comment;
    var $name;
    var $default;
    
    function Column() {
        $this->name = null;
        $this->comment = null;
        $this->visible = true;
        $this->editable = true;
        $this->validators = array();
    }

    function add_validator($validator) {
        $this->validators[] = $validator;
    }

    function validate_value($value, &$msg) {
        foreach ($this->validators as $validator) {
            if (!$validator($value, &$msg)) {
                return false;
            }
        }
        return true;
    }

    function set_default($value) {
        $this->default = $value;
    }
};


//---------------------------------------------------------------------------//
// Simple DB interface                                                       //
//---------------------------------------------------------------------------//

/**
 * Simple database table interface
 */
class DBTable
{
    var $connection;
    var $table_name;
    var $primary_key;
    var $fields;

    function DBTable($connection, $table_name) {
        $this->connection = $connection;
        $this->table_name = $table_name;
        $this->scan_table_structure();
    }

    function scan_table_structure() {
        $this->fields = array();
        $result = $this->query("DESC {$this->table_name};");
        while ($row = $result->fetch_object()) {
            if ($row->Key == 'PRI') {
                $this->primary_key = $row->Field;
            }
            $this->fields[] = $row->Field;
        }
    }
    
    function query($query) {
        $result = mysql_query($query, $this->connection);
        return new DBResult($result, $this);
    }

    function key_is($entry_key) {
        return "{$this->primary_key} = '".$this->escape($entry_key)."'";
    }

    function select($fields='*', $rest='') {
        $query = "SELECT {$fields} FROM {$this->table_name} $rest;";
        return $this->query($query);
    }

    function update($row, $entry_key=null) {
        if (!$entry_key) $entry_key = $row[$this->primary_key];
        $selection = $this->key_is($entry_key);
        
        $assign = array();
        foreach ($row as $key => $value) {
            $assign[] = "$key = '" . $this->escape($value) . "'";
        }
        $assign[] = $selection;

        $query = "UPDATE {$this->table_name} SET " . join(", ", $assign) . " WHERE $selection;";        
        return $this->query($query);
    }

    function insert($row) {
        $fields = array();
        $values = array();
        foreach ($row as $key => $value) {
            $fields[] = $key;
            $values[] = "'" . $this->escape($value) . "'";;
        }
        $query = "INSERT INTO {$this->table_name} (" . join(",", $fields) . ") VALUES (" . join(",", $values) . ");";
        return $this->query($query);
    }

    function delete($entry_key) {
        $selection = $this->key_is($entry_key);
        $query = "DELETE FROM {$this->table_name} WHERE $selection;";
        return $this->query($query);
    }

    function fetch_one_assoc($query) {
        $result = $this->query($query);
        return $result->fetch_assoc();
    }

    function fetch_one_object($query) {
        $result = $this->query($query);
        return $result->fetch_object();
    }

    function escape($string) {
        return mysql_real_escape_string($string, $this->connection);
    }

    function error() {
        return mysql_error($this->connection);
    }
};

/**
 * Simple database query result interface
 */
class DBResult
{
    var $result;

    function DBResult($result, $connection) {
        $this->result = $result;
        if ($result) {
            $this->last_id = mysql_insert_id($connection->connection);
        } else {
            $this->error = $connection->error();
        }
    }
    function fetch_assoc() { return mysql_fetch_assoc($this->result); }
    function fetch_object() { return mysql_fetch_object($this->result); }
};


//---------------------------------------------------------------------------//
// Callback handler                                                          //
//---------------------------------------------------------------------------//

/**
 * Callback list
 */
class Callback
{
    var $before_change_callbacks;
    var $after_change_callbacks;

    var $before_delete_callbacks;
    var $after_delete_callbacks;

    var $display_callbacks;

    var $columns;

    function Callback(&$columns) {
        $this->columns = $columns;

        $this->before_change_callbacks = array();
        $this->after_change_callbacks = array();
        
        $this->before_delete_callbacks = array();
        $this->after_delete_callbacks = array();

        $this->display_callbacks = array();
    }

    function before_change($row, &$errors) {
        $ok = true;
        foreach ($this->columns as $field_name => $column) {
            if (!$column->validate_value($row[$field_name], $msg)) {
                $errors[$field_name][] = "{$column->name}: $msg";
                $ok = false;
            }
        }
        foreach ($this->before_change_callbacks as $cb) {
            if (!$cb($row, $msg)) { $errors[null][] = $msg; $ok = false; }
        }
        return $ok;
    }

    function after_change($row, &$errors) {
        $ok = true;
        foreach ($this->after_change_callbacks as $cb) {
            if (!$cb($row, $msg)) { $errors[null][] = $msg; $ok = false; }
        }
        return $ok;
    }

    function before_delete($row, &$errors) {
        $ok = true;
        foreach ($this->before_delete_callbacks as $cb) {
            if (!$cb($row, $msg)) { $errors[null][] = $msg; $ok = false; }
        }
        return $ok;
    }

    function after_delete($row, &$errors) {
        $ok = true;
        foreach ($this->before_delete_callbacks as $cb) {
            if (!$cb($row, $msg)) { $errors[null][] = $msg; $ok = false; }
        }
        return $ok;
    }

    function before_display($row, $field, $proposed_display, &$cell_attr) {
        foreach ($this->display_callbacks as $cb) {
            $cb($row, $field, $proposed_display, $cell_attr);
        }
        return $proposed_display;
    }
};

/**
 * Format a table cell, calling all callbacks and applying attributes
 */
function do_format_cell($callback, $row, $field_name, $value) {
    $cell_attr = array();
    $disp = $callback->before_display($row, $field_name, $value, $cell_attr);
    $attr = "";
    foreach ($cell_attr as $key => $value) {
        $attr .= " $key=\"$value\"";
    }
    return "<td$attr>$disp</td>\n";
 }


//---------------------------------------------------------------------------//
// Record editor                                                             //
//---------------------------------------------------------------------------//

/**
 * Table editor class: editing single rows.
 */
class TableEdit
{
    var $conn;
    var $columns;
    var $callback;

    function TableEdit($conn, &$columns, &$callback) {
        $this->conn = $conn;
        $this->columns = $columns;
        $this->callback = $callback;
    }

    /**
     * Generate an edit form.
     * @returns string containing form elements in a <table>
     */
    function get_edit_form($row, $hilight = false, $fill_defaults = false) {
        $output .= "<table>";
        foreach ($this->columns as $field_name => $column) {
            $output .= "<tr>";
            if ($hilight[$field_name]) {
                $output .= "<td><span style='color: #f00'>{$column->name}</span></td>\n";
            } else {
                $output .= "<td>{$column->name}</td>\n";
            }

            if ($fill_defaults) {
                $value = $column->default;
            } else {
                $value = $row[$field_name];
            }
            if ($column->editable and $column->editor) {
                $disp = $column->editor->get_form("edit_$field_name", $value);
                $output .= "<td>{$disp}</td>\n";
            } else {
                #$output .= do_format_cell($this->callback, $row, $field_name,
                #                          $column->display->get($value));
                $output .= "<td>" . $column->display->get($value) . "</td>";
            }
            if ($column->comment) {
                $output .= "<td>{$column->comment}</td>";
            }
            $output .= "</tr>";
        }
        $output .= "</table>";
        return $output;
    }

    /**
     * Show the edit/insert form
     */
    function action_input($entry_key, $row=null, $hilight=array()) {
        if (!$row and $entry_key) {
            $result = $this->conn->select(
                '*', "WHERE " . $this->conn->key_is($entry_key));
            $row = $result->fetch_assoc();
        }

        $output = "";
        $output .= "<form method='post'>";
        $output .= $this->get_edit_form($row, $hilight, $row==null);
        if ($entry_key) {
            $output .= "<input type='submit' name='submit' value='update'>";
            $output .= "<input type='submit' name='submit' value='delete'>";
            $output .= "</form>";
        } else {
            $output .= "<input type='submit' name='submit' value='insert'>";
            $output .= "</form>";
        }

        print $output;
    }

    /**
     * Get form input from _POST
     */
    function get_input_row() {
        $row = array();
        foreach ($this->columns as $field_name => $column) {
            if ($column->editable and $column->editor) {
                $row[$field_name] = $column->editor->get_value("edit_$field_name");
            }
        }
        return $row;
    }

    /**
     * Validate the input row.
     *
     * If it fails, print the errors, an edit form, and ask the user to fix the
     * errors.
     */
    function action_validate_change($row) {
        if (!$this->callback->before_change($row, $errors)) {
            $hilight = $this->display_errors("Some of the values you input were invalid. <span style='color: #a00;'>Please correct the following and try again:</span>", $errors);
            $entry_key = $row[$this->conn->primary_key];
            $this->action_input($entry_key, $row, $hilight);
            return false;
        }
        return true;
    }

    /**
     * Call all 'before_delete' callbacks, including column data validation.
     * Print error messages and the input form if fails.
     */
    function action_validate_delete($row) {
        if (!$this->callback->before_delete($row, $errors)) {
            $hilight = $this->display_errors("Failed to delete a row:", $errors);
            $entry_key = $row[$this->conn->primary_key];
            $this->action_input($entry_key, $row, $hilight);
            return false;
        }
        return true;
    }

    /**
     * Return a array(null => error_message, key1 => failed, ...)
     * representing what failed.
     */
    function display_errors($msg, $errors) {
        if (count($errors) == 0) return;
        $output = "<p>$msg</span><ul>\n";
        $hilight = array();
        foreach ($errors as $error_type => $these_errors) {
            $hilight[$error_type] = true;
            foreach ($these_errors as $error) {
                $output .= "<li>$error</li>\n";
            }
        }
        $output .= "</ul></p>\n";
        print $output;
        return $hilight;
    }

    /**
     * Try to update the row with the values found in _POST.
     * On success, print message.
     * On failure, reprint input form with error messages.
     */
    function action_update() {
        $row = $this->get_input_row();
        $entry_key = $row[$this->conn->primary_key];
        if (!$this->action_validate_change($row)) return;

        // Perform update
        $result = $this->conn->update($row, $entry_key);

        // Show the result
        if (!$result->error) {
            print "<p>Updated a row successfully:</p>";
            $view = new TableView($this->conn, $this->columns,
                                  $this->callback);
            $view->filter = "WHERE " . $this->conn->key_is($entry_key);
            print $view->get_table_view();
            
            // After callbacks
            $this->callback->after_change($row, $errors);
            $this->display_errors(".. but some errors occurred afterwards:", $errors);
        } else {
            print "<p>Failed to update a row: $result->error</p>";
            $this->action_input($entry_key, $row);
        }
    }

    /**
     * Try to delete the indicated row.
     * On success, print message.
     * On failure, reprint input form with error messages.
     */
    function action_delete() {
        $row = $this->get_input_row();
        $entry_key = $row[$this->conn->primary_key];
        $selection = $this->conn->key_is($entry_key);
        $row = $this->conn->fetch_one_assoc("SELECT * FROM {$this->conn->table_name} WHERE $selection;");

        if (!$this->action_validate_delete($row)) return;

        // Perform delete
        $result = $this->conn->delete($entry_key);

        // Show the result
        if (!$result->error) {
            print "<p>Deleted successfully.</p>";

            // After callbacks
            $this->callback->after_delete($row, $errors);
            $this->display_errors(".. but some errors occurred afterwards:", $errors);
        } else {
            print "<p>Deleting a row failed.</p>";
            $this->action_input($entry_key, $row);
        }
    }

    /**
     * Try to insert a new row, using values found from _POST.
     * On success, print message.
     * On failure, reprint input form with error messages.
     */
    function action_insert() {
        $row = $this->get_input_row();
        $row[$this->conn->primary_key] = null;
        $entry_key = null;
        if (!$this->action_validate_change($row)) return;

        // Perform insert
        $result = $this->conn->insert($row);

        // Show the result
        if (!$result->error) {
            $result = $this->conn->select(
                '*', 'WHERE ' . $this->conn->key_is($result->last_id));
            $row = $result->fetch_assoc();
            $selection = $this->conn->key_is($row[$this->conn->primary_key]);

            print "<p>Inserted a row successfully:</p>";
            $view = new TableView($this->conn, $this->columns,
                                  $this->callback);
            $view->filter = "WHERE $selection";
            print $view->get_table_view();
        } else {
            print "<p>Failed to insert a row: {$result->error}</p>";
            $this->action_input($entry_key, $row);
        }
    }
    
    /**
     * Entry point.
     */
    function display() {
        switch ($_POST['submit']) {
        case 'update':
            $this->action_update();
            break;
        case 'delete':
            $this->action_delete();
            break;
        case 'insert':
            $this->action_insert();
            break;
        default:
            $this->action_input($_GET['id']);
        };
    }
};


//---------------------------------------------------------------------------//
// Table viewer                                                              //
//---------------------------------------------------------------------------//

class TableSort
{
    var $sort_field;
    var $sort_dir;
    var $sort_sql;

    var $conn;
    var $columns;

    function TableSort(&$conn, &$columns,
                       $sort_field = null, $sort_dir = null) {
        $this->columns = $columns;
        $this->conn = $conn;
        
        if ($_GET['sort_field'] == null) $_GET['sort_field'] = $sort_field;
        if ($_GET['sort_dir'] == null) $_GET['sort_dir'] = $sort_dir;
        
        $field = $_GET['sort_field'];
        if (!in_array($field, $this->conn->fields)) {
            $this->sort_field = $this->conn->primary_key;
        } else {
            $this->sort_field = $field;
        }

        $dir = $_GET['sort_dir'];
        if (!in_array($dir, array('0', '1'))) {
            $this->sort_dir = '0';
        } else {
            $this->sort_dir = $dir;
        }
    }

    /**
     * Formulate an ORDER BY statement based on _GET information.
     */
    function get_sql() {
        $dir = $this->sort_dir ? 'DESC' : 'ASC';
        return "ORDER BY {$this->sort_field} $dir";
    }

    /**
     * Formulate a HTML <table> header
     */
    function get_table_header() {
        $output = "";
        $url = new My_URL();
        foreach ($this->columns as $field_name => $column) {
            if (!$column->visible) continue;
            $url->addQueryString('sort_field', $field_name);
            if ($this->sort_field == $field_name) {
                if ($this->sort_dir) {
                    $ch = "_";
                    $url->addQueryString('sort_dir', 0);
                } else {
                    $ch = "^";
                    $url->addQueryString('sort_dir', 1);
                }
            } else {
                $ch = "-";
                $url->addQueryString('sort_dir', 0);
            }
            $output .= "<th><a href=\"" . $url->getURL(true) . "\">{$column->name} [$ch]</a></th>";
        }
        return $output;
    }
};

/**
 * Display a table.
 */
class TableView
{
    var $conn;
    var $columns;
    var $callback;

    var $sort;

    var $filter_sql;
    var $filters;

    function TableView($conn, &$columns, &$callback) {
        $this->conn = $conn;
        $this->columns = $columns;
        $this->callback = $callback;
        $this->get_filter();
        $this->sort = new TableSort($conn, $columns);
    }

    /**
     * Formulate a WHERE statement based on _GET information.
     * Also stuff the filter data.
     */
    function get_filter() {
        $this->filters = array();
        $searches = array();
        foreach ($_GET as $key => $value) {
            if (preg_match('/^search_([0-9]+)$/', $key) and
                preg_match('/^(.*?):(.*?):(.*)$/',$value,$matches)) {

                if (!in_array($matches[1], $this->conn->fields))
                    continue;
                if (!in_array($matches[2], array('LIKE', '>', '<', '=')))
                    continue;

                $search = array($matches[1],
                                $matches[2],
                                $matches[3]);
                
                $this->filters[] = $search;

                $searches[] = $this->conn->escape($matches[1]) . " "
                    . $matches[2] . " '" . $this->conn->escape($matches[3])
                    . "'";
            }
        }
        if (!$searches) return;
        if ($_GET['search_or']) {
            $this->filter_sql = "WHERE " . join(" OR ", $searches);
        } else {
            $this->filter_sql = "WHERE " . join(" AND ", $searches);
        }
    }

    /**
     * Return a view of the table, using the current sort+filter settings.
     */
    function get_table_view() {
        $query = "SELECT * FROM {$this->conn->table_name} {$this->filter} " . $this->sort->get_sql() . ";";
        $result = $this->conn->query($query);

        if ($result->error) {
            return "<p>$query: $result->error</p>";
        }
        
        $output = "";

        $output .= "<p>Query: " . htmlentities($query) .  "</p>\n";
        $output .= "<table><tr><th></th>";
        $output .= $this->sort->get_table_header();
        $output .= "</tr>";
        
        $url = new My_URL();
        $url->addQueryString('action', 'edit');
        
        while ($row = $result->fetch_assoc()) {
            $output .= "<tr>";

            $url->addQueryString('id', $row[$this->conn->primary_key]);
            $output .= do_format_cell(
                $this->callback, $row, null,
                "<a href=\"" . $url->getURL(true) . "\">&raquo;</a>");
            
            foreach ($this->columns as $field_name => $column) {
                if (!$column->visible) continue;
                $value = $row[$field_name];
                $output .= do_format_cell($this->callback, $row, $field_name,
                                          $column->display->get($value));
            }
            $output .= "</tr>";
        }

        $output .= "</table>";

        return $output;
    }

    /**
     * Entry point. Display a table view, using the current settings.
     */
    function display() {
        print $this->get_table_view();
    }
};


//---------------------------------------------------------------------------//
// Programmer API & controller                                               //
//---------------------------------------------------------------------------//

function fold_list_to_map($list) {
    $map = array();
    for ($i = 0; $i < count($list); $i += 2) {
        $map[$list[$i]] = $list[$i+1];
    }
    return $map;
}

/**
 * Controller and API for the table editor.
 */
class PhpTableau
{
    var $conn;
    var $columns;
    var $callback;
    
    function PhpTableau($connection, $table_name) {
        $this->conn = new DBTable($connection, $table_name);
        $this->columns = array();
        $this->callback = new Callback($columns);
    }

    function set_columns() {
        $columns = fold_list_to_map(func_get_args());
        
        foreach ($columns as $id => &$value) {
            if (!$value->name) $value->name = $id;
        }
        $this->columns = $columns;
        $this->callback->columns = $columns;
    }

    function set_editable() {
        $status = fold_list_to_map(func_get_args());
        foreach ($status as $key => $value) {
            $this->columns[$key]->editable = $value;
        }
    }

    function set_visible() {
        $status = fold_list_to_map(func_get_args());
        foreach ($status as $key => $value) {
            $this->columns[$key]->visible = $value;
        }
    }

    function set_comment() {
        $status = fold_list_to_map(func_get_args());
        foreach ($status as $key => $value) {
            $this->columns[$key]->comment = $value;
        }
    }

    function set_name($status) {
        $status = fold_list_to_map(func_get_args());
        foreach ($status as $key => $value) {
            $this->columns[$key]->name = $value;
        }
    }

    function set_default($status) {
        $status = fold_list_to_map(func_get_args());
        foreach ($status as $key => $value) {
            $this->columns[$key]->default = $value;
        }
    }

    function add_callback($place, $cb) {
        switch ($place) {
        case 'before_change':
            $this->callback->before_change_callbacks[] = $cb;
            break;
        case 'after_change':
            $this->callback->after_change_callbacks[] = $cb;
            break;
        case 'before_delete':
            $this->callback->before_delete_callbacks[] = $cb;
            break;
        case 'after_delete':
            $this->callback->after_delete_callbacks[] = $cb;
            break;
        case 'display':
            $this->callback->display_callbacks[] = $cb;
            break;
        default:
            die("$place is not a valid callback type.");
            break;
        }
    }

    function add_validator($field, $cb) {
        $this->columns[$field]->add_validator($cb);
    }

    function display() {
        // Normalize GPC
        if (get_magic_quotes_gpc()) {
            foreach ($_POST as $key => $value) {
                $_POST[$key] = stripslashes($value);
            }
            foreach ($_GET as $key => $value) {
                $_GET[$key] = stripslashes($value);
            }
            foreach ($_REQUEST as $key => $value) {
                $_REQUEST[$key] = stripslashes($value);
            }
        }

        // Navigation links
        $url = new My_URL();
        $url->removeQueryString('id');
        $url->addQueryString('action', 'view');
        print "<a href=\"".$url->getURL(true)."\">View all</a> ";
        $url->addQueryString('action', 'edit');
        print "<a href=\"".$url->getURL(true)."\">Insert a new row</a>";

        #foreach ($_POST as $key => $value) {
        #    print "POST[$key] = '$value'<br>";
        #}

        // Table view / editor
        switch ($_GET['action']) {
        case null:
        case 'view':
            $view = new TableView($this->conn, $this->columns,
                                  $this->callback);
            $view->display();
            break;
        case 'edit':
            $view = new TableEdit($this->conn, $this->columns,
                                  $this->callback);
            $view->display();
            break;
        }
    }
};


//---------------------------------------------------------------------------//
// Cell renderers and editors                                                //
//---------------------------------------------------------------------------//


//
// --- Text columns ---------------------------------------------------------
//

class TextEditor extends Editor
{
    function get_form($prefix, $value) {
        return "<input name=\"{$prefix}_text\" type=text value=\"$value\">";
    }

    function get_value($prefix) {
        return $_POST["{$prefix}_text"];
    }
};

class TextDisplay extends Display
{
    function get($value) {
        return htmlentities($value);
    }
};

class TextColumn extends Column
{
    function TextColumn() {
        Column::Column();
        $this->editor = new TextEditor();
        $this->display = new TextDisplay();
    }
};


//
// --- Date columns ---------------------------------------------------------
//

function parse_date($string) {
    if (!$string) return array(null, null, null);
    return array(substr($string, 0, 4), substr($string, 5, 2),
                 substr($string, 8, 2));
}

function parse_datetime($string) {
    if (!$string) return array(null, null, null, null, null, null);
    return array_merge(parse_date($string),
                       array(substr($string, 11, 2), substr($string, 14, 2),
                             substr($string, 17, 2)));
}

function format_date($ar) {
    if ($ar[0] != null and $ar[1] != null and $ar[2] != null) {
        return sprintf("%04d-%02d-%02d", $ar[0], $ar[1], $ar[2]);
    } else {
        return null;
    }
}

function format_datetime($ar) {
    $date = format_date($ar);
    if ($ar[3] != null and $ar[4] != null and $ar[5] != null) {
        return $date . sprintf(" %02d:%02d:%02d", $ar[3], $ar[4], $ar[5]);
    } else {
        return $date;
    }
}

function create_select_form($name, $is_map, $values, $options, $selected) {
    $form = "<select name=\"$name\" $options>\n";
    $form .= "  <option value=\"\"></option>\n";
    foreach ($values as $key => $value) {
        if (!$is_map) $key = $value;
        
        $form .= "  <option value=\"$key\"";
        if ($selected == $key) {
            $form .= " selected>";
        } else {
            $form .= ">";
        }
        $form .= "$value</option>\n";
    }
    $form .= "</select>\n";
    return $form;
}

function format_range($fmt, $min, $max, $step=1) {
    $value = range($min, $max, $step);
    foreach ($value as &$val) {
        $val = sprintf($fmt, $val);
    }
    return $value;
}
    
class DateEditor extends Editor
{
    var $months = array(1 => 'Jan',  2 => 'Feb',  3 => 'Mar', 4 => 'Apr',
                        5 => 'May',  6 => 'Jun',  7 => 'Jul', 8 => 'Aug',
                        9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec');
    var $min_year;
    var $max_year;

    function DateEditor($min_year=null, $max_year=null) {
        $this->set_year_range($min_year, $max_year);
    }

    function set_year_range($min_year, $max_year) {
        if (!$min_year) $min_year = date('Y') - 10;
        if (!$max_year) $max_year = date('Y') + 10;
        $this->min_year = $min_year;
        $this->max_year = $max_year;
    }

    function get_form($prefix, $value) {
        $date = parse_date($value);

        $year_range = array_reverse(range($this->min_year, $this->max_year));
        if ($date[0] != null and ($date[0] < $this->min_year or
                                  $date[0] > $this->max_year)) {
            $year_range[] = $date[0];
        }

        $form = "";
        $form .= create_select_form("{$prefix}_year", false, $year_range,
                                    "", $date[0]);
        $form .= create_select_form("{$prefix}_month", true, $this->months,
                                    "", $date[1]);
        $form .= create_select_form("{$prefix}_day", false, range(1, 31),
                                    "", $date[2]);
        return $form;
    }

    function get_value($prefix) {
        $year = $_POST["{$prefix}_year"];
        $month = $_POST["{$prefix}_month"];
        $day = $_POST["{$prefix}_day"];
        return format_date(array($year, $month, $day));
    }
};

class DateColumn extends TextColumn
{
    function DateColumn() {
        TextColumn::TextColumn();
        $this->editor = new DateEditor();
        $this->display = new TextDisplay();
    }

    function set_year_range($min_year, $max_year) {
        $this->editor->set_year_range($min_year, $max_year);
    }
};

class DateTimeEditor extends DateEditor
{
    function DateTimeEditor() {
        DateEditor::DateEditor();
    }

    function get_form($prefix, $value) {
        $form = DateEditor::get_form($prefix, $value);

        $date = parse_datetime($value);

        $form .= ", ";
        $form .= create_select_form("{$prefix}_hour",
                                    false, format_range("%02d", 0, 23),
                                    "", $date[3]);
        $form .= ":";
        $form .= create_select_form("{$prefix}_min",
                                    false, format_range("%02d", 0, 23),
                                    "", $date[4]);
        $form .= ":";
        $form .= create_select_form("{$prefix}_sec",
                                    false, format_range("%02d", 0, 23),
                                    "", $date[5]);

        return $form;
    }

    function get_value($prefix) {
        $year = $_POST["{$prefix}_year"];
        $month = $_POST["{$prefix}_month"];
        $day = $_POST["{$prefix}_day"];
        $hour = $_POST["{$prefix}_hour"];
        $min = $_POST["{$prefix}_min"];
        $sec = $_POST["{$prefix}_sec"];
        return format_datetime(array($year, $month, $day, $hour, $min, $sec));
    }
};

class DateTimeColumn extends DateColumn
{
    function DateTimeColumn() {
        TextColumn::TextColumn();
        $this->editor = new DateTimeEditor();
        $this->display = new TextDisplay();
    }
};


//
// --- ID columns -----------------------------------------------------------
//

class IDDisplay extends TextDisplay
{
    function get($value) {
        if ($value) {
            return "<span style='color: #aaa;'>$value</span>";
        } else {
            return "<span style='color: #aaa;'>[new]</span>";
        }
    }
};

class IDEditor extends Editor
{
    function get_form($prefix, $value) {
        return "<input name=\"{$prefix}_id\" type='hidden' value=\"$value\">" . IDDisplay::get($value);
    }
    function get_value($prefix) {
        return $_POST[$prefix . '_id'];
    }
};

class IDColumn extends TextColumn
{
    function IDColumn() {
        TextColumn::TextColumn();
        $this->editor = new IDEditor();
        $this->display = new IDDisplay();
    }
};


//
// --- Last updated column --------------------------------------------------
//

class LastUpdatedEditor extends Editor
{
    function get_form($prefix, $value) {
        return htmlentities(date('Y-m-d H:i:s'));
    }
    function get_value($prefix) {
        return date('Y-m-d H:i:s');
    }
};

class LastUpdatedColumn extends Column
{
    function LastUpdatedColumn() {
        Column::Column();
        $this->editor = new LastUpdatedEditor();
        $this->display = new TextDisplay();
    }
}


//
// --- Choice columns -------------------------------------------------------
//

class ChoiceEditor extends Editor
{
    var $choices;
    var $is_map;
    
    function ChoiceEditor($choices, $is_map = false) {
        $this->choices = $choices;
        $this->is_map = $is_map;
    }

    function get_form($prefix, $value) {
        return create_select_form("{$prefix}_choice",
                                  $this->is_map, $this->choices,
                                  "", $value);
    }

    function get_value($prefix) {
        return $_POST["{$prefix}_choice"];
    }
};

class ChoiceDisplay extends Display
{
    var $choices;
    var $is_map;

    function ChoiceDisplay($choices, $is_map) {
        $this->choices = $choices;
        $this->is_map = $is_map;
    }
    
    function get($value) {
        if ($is_map) {
            return $choices[$value];
        } else {
            return $value;
        }
    }
};

class ChoiceColumn extends TextColumn
{
    function ChoiceColumn($choices, $is_map = false) {
        TextColumn::TextColumn();
        $this->editor = new ChoiceEditor($choices, $is_map);
        $this->display = new ChoiceDisplay($choices, $is_map);
    }

    function set_choices($choices, $is_map=false) {
        $this->editor->choices = $choices;
        $this->editor->is_map = $is_map;
        $this->display->choices = $choices;
        $this->display->is_map = $is_map;
    }
};


//
// --- Foreign key columns -------------------------------------------------
//

class ForeignKeyColumn extends ChoiceColumn
{
    function ForeignKeyColumn($connection, $table,
                              $key_field, $value_field, $query='') {
        $choices = $this->get_choices($connection, $table, $key_field,
                                      $value_field, $query);
        ChoiceColumn::ChoiceColumn($choices, $key_field != $value_field);
    }

    function get_choices($connection, $table, $key_field, $value_field,
                         $query='') {
        $db = new DBTable($connection, $table);

        if (!$query) {
            $query = "SELECT {$key_field}, {$value_field} FROM {$table};";
        }

        $result = $db->query($query);
        if ($result->error) die($result->error);
        
        $choices = array();
        if ($key_field != $value_field) {
            while ($row = $result->fetch_assoc()) {
                $choices[$row[$key_field]] =
                    $row[$value_field] . " ($row[$key_field])";
            }
        } else {
            while ($row = $result->fetch_assoc()) {
                $choices[] = $row[$key_field];
            }
        }
        return $choices;
    }
};


//---------------------------------------------------------------------------//
// Utilities                                                                 //
//---------------------------------------------------------------------------//


/**
 * Necessary to allow translation of ampersands to their entity equivalent.
 * This is due to MSIE replacing &copy= in urls with the copyright symbol,
 * despite the lack of ending semi-colon... :-/
 */
class My_URL extends Net_URL
{
    /**
    * Returns full url
    *
    * @param  bool   $convertAmpersands Whether to convert & to &amp;
    * @return string                    Full url
    * @access public
    */
    function getURL($convertAmpersands = false)
    {
        $querystring = $this->getQueryString();

        if ($convertAmpersands) {
            $querystring = str_replace('&', '&amp;', $querystring);
            // This is the key difference to My_URL
        }

        $this->url = $this->protocol . '://'
                   . $this->user . (!empty($this->pass) ? ':' : '')
                   . $this->pass . (!empty($this->user) ? '@' : '')
                   . $this->host . ($this->port == $this->getStandardPort($this->protocol) ? '' : ':' . $this->port)
                   . $this->path
                   . (!empty($querystring) ? '?' . $querystring : '')
                   . (!empty($this->anchor) ? '#' . $this->anchor : '');

        return $this->url;
    }
}
