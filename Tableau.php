<? # -*-php-*-

//---------------------------------------------------------------------------//
// =======                                                                   //
// Tableau                                                                   //
// =======                                                                   //
//                                                                           //
// Database table editor component written in PHP.                           //
//                                                                           //
// Copyright (C) 2006   Pauli Virtanen <pauli.virtanen@iki.fi>               //
//                                                                           //
// This script is free software;  you can redistribute it and/or modify      //
// it under the terms of the GNU General Public License (version 2)  as      //
// published by the Free Software Foundation.                                //
//                                                                           //
// The GNU General Public License can be found at                            //
// http://www.gnu.org/copyleft/gpl.html.                                     //
//                                                                           //
// This script is distributed in the hope that it will be useful, but        //
// WITHOUT  ANY  WARRANTY;  without  even  the  implied  warranty  of        //
// MERCHANTABILITY  or  FITNESS  FOR  A  PARTICULAR  PURPOSE. See the        //
// GNU General Public License for more details.                              //
//                                                                           //
//---------------------------------------------------------------------------//


require("Net/URL.php");


//---------------------------------------------------------------------------//
//                                                                           //
// Abstract base for table cell renderers and editors & columns              //
//                                                                           //
//---------------------------------------------------------------------------//

/**
 * Base class for editors
 */
class Tableau_Editor
{
    function Tableau_Editor() {}
    function get_form($prefix, $value) {}
    function get_value($prefix) {}
};

/**
 * Base class for cell displays
 */
class Tableau_Display
{
    function Tableau_Display() {}
    function get($value) {}
};

/**
 * Base class for columns
 */
class Tableau_Column
{
    var $visible;
    var $editable;
    var $validators;
    var $editor;
    var $display;
    var $comment;
    var $name;
    var $field_name;
    var $default;
    
    function Tableau_Column() {
        $this->name = null;
        $this->comment = null;
        $this->visible = true;
        $this->editable = true;
        $this->validators = array();
    }

    function add_validator($validator) {
        $this->validators[] = $validator;
    }

    function validate_value($action, &$value, &$msg, &$row) {
        foreach ($this->validators as $validator) {
            if (!$validator($action, $value, &$msg)) {
                return false;
            }
        }
        return true;
    }

    function finalize_value($action, &$value, &$msg, &$row) { return true; }

    function set_default($value) {
        $this->default = $value;
    }
};


//---------------------------------------------------------------------------//
//                                                                           //
// Simple DB interface                                                       //
//                                                                           //
//---------------------------------------------------------------------------//

/**
 * Simple database table interface
 */
class Tableau_DBTable
{
    var $connection;
    var $table_name;
    var $primary_key;
    var $fields;
    var $has_null;
    var $nrows;

    function Tableau_DBTable($connection, $table_name) {
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
	    if ($row->Null) {
	        $this->has_null[$row->Field] = true;
	    }
            $this->fields[] = $row->Field;
        }
    }
    
    function query($query) {
        $result = mysql_query($query, $this->connection);
        return new Tableau_DBResult($result, $this);
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
	    if ($value === null and $this->has_null[$key]) {
	        $assign[] = "$this->table_name.$key = NULL";
	    } else {
                $assign[] = "$this->table_name.$key = '" . $this->escape($value) . "'";
            }
        }
        $assign[] = $selection;

        $query = "UPDATE {$this->table_name} SET " . join(", ", $assign) . " WHERE $selection;";        
        return $this->query($query);
    }

    function insert($row) {
        $fields = array();
        $values = array();
        foreach ($row as $key => $value) {
            $fields[] = $this->table_name . "." . $key;
	    if ($value === null and $this->has_null[$key]) {
	        $values[] = "NULL";
	    } else {
                $values[] = "'" . $this->escape($value) . "'";;
	    }
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
class Tableau_DBResult
{
    var $result;

    function Tableau_DBResult($result, $connection) {
        $this->result = $result;
        if ($result) {
            $this->last_id = mysql_insert_id($connection->connection);
        } else {
            $this->error = $connection->error();
        }
    }
    function fetch_assoc() { return mysql_fetch_assoc($this->result); }
    function fetch_object() { return mysql_fetch_object($this->result); }
    function fetch_array() { return mysql_fetch_array($this->result); }
};


//---------------------------------------------------------------------------//
//                                                                           //
// Callback handler                                                          //
//                                                                           //
//---------------------------------------------------------------------------//

/**
 * Callback list
 */
class Tableau_Callback
{
    var $before_change_callbacks;
    var $after_change_callbacks;

    var $before_delete_callbacks;
    var $after_delete_callbacks;

    var $display_callbacks;
    var $display_row_callbacks;

    var $columns;

    function Tableau_Callback(&$columns) {
        $this->columns = $columns;

        $this->before_change_callbacks = array();
        $this->after_change_callbacks = array();
        
        $this->before_delete_callbacks = array();
        $this->after_delete_callbacks = array();

        $this->display_callbacks = array();
        $this->display_row_callbacks = array();
    }

    function before_change($action, &$row, &$errors) {
        $ok = true;
        foreach ($this->columns as $field_name => $column) {
            if (!$column->validate_value($action, $row[$field_name], $msg, $row)) {
                $errors[$field_name][] = "{$column->name}: $msg";
                $ok = false;
            }
        }
        foreach ($this->before_change_callbacks as $cb) {
            if (!$cb($action, $row, $msg)) { $errors[null][] = $msg; $ok = false; }
        }
        return $ok;
    }

    function after_change($action, &$row, &$errors) {
        $ok = true;
        foreach ($this->columns as $field_name => $column) {
            if (!$column->finalize_value($action, $row[$field_name], $msg, $row)) {
                $errors[$field_name][] = "{$column->name}: $msg";
                $ok = false;
            }
        }
        foreach ($this->after_change_callbacks as $cb) {
            if (!$cb($action, $row, $msg)) { $errors[null][] = $msg; $ok = false; }
        }
        return $ok;
    }

    function before_delete(&$row, &$errors) {
        $ok = true;
        foreach ($this->before_delete_callbacks as $cb) {
            if (!$cb($row, $msg)) { $errors[null][] = $msg; $ok = false; }
        }
        return $ok;
    }

    function after_delete(&$row, &$errors) {
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

    function before_display_row($row, $proposed_display, &$row_attr) {
        foreach ($this->display_row_callbacks as $cb) {
            $cb($row, $proposed_display, $row_attr);
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

function do_format_row($callback, $row, $value, $row_attr) {
    $disp = $callback->before_display_row($row, $value, $row_attr);
    $attr = "";
    foreach ($row_attr as $key => $value) {
        $attr .= " $key=\"$value\"";
    }
    return "<tr$attr>$disp</tr>\n";
 }


//---------------------------------------------------------------------------//
//                                                                           //
// Record editor                                                             //
//                                                                           //
//---------------------------------------------------------------------------//

/**
 * Table editor class: editing single rows.
 */
class Tableau_TableEdit
{
    var $conn;
    var $columns;
    var $callback;
    var $commentary;

    function Tableau_TableEdit($conn, &$columns, &$callback, $commentary) {
        $this->conn = $conn;
        $this->columns = $columns;
        $this->callback = $callback;
	$this->commentary = $commentary;
    }

    /**
     * Generate an edit form.
     * @returns string containing form elements in a <table>
     */
    function get_edit_form($row, $hilight = false, $fill_defaults = false) {
        $output .= "<table>\n";
        foreach ($this->columns as $field_name => $column) {
            $output .= "<tr>\n";
            if ($hilight[$field_name]) {
                $output .= "<td class='name'><span style='color: #f00'>{$column->name}</span></td>\n";
            } else {
                $output .= "<td class='name'>{$column->name}</td>\n";
            }

            if ($fill_defaults) {
                $value = $column->default;
            } else {
                $value = $row[$field_name];
            }
            if ($column->editable and $column->editor !== null) {
                $disp = $column->editor->get_form("edit_$field_name", $value);
                $output .= "<td class='value'>{$disp}\n";
            } else {
                $output .= "<td class='value'>" . $column->display->get($value) . "\n";
            }
            if ($column->comment) {
                $output .= "<span class='comment'>{$column->comment}</span>\n";
            }
            $output .= "</td></tr>\n";
        }
        $output .= "</table>\n";
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
        if ($entry_key) {
            print "<h2>Edit row</h2>\n";
        } else {
            print "<h2>Insert row</h2>\n";
        }

        $output = "";
        $output .= "<form enctype='multipart/form-data' method='post'>\n";
        $output .= $this->get_edit_form($row, $hilight, $row==null);
        if ($entry_key) {
            $output .= "<div class='buttonbox'>";
            $output .= "<input type='submit' name='submit' value='Update'>\n";
            $output .= "<input type='submit' name='submit' value='Delete'>\n";
            $output .= "<input type='reset' name='Reset'>\n";
            $output .= "</div></form>\n";
        } else {
            $output .= "<div class='buttonbox'>";
            $output .= "<input type='submit' name='submit' value='Insert'>\n";
            $output .= "<input type='reset' name='reset' value='Reset'>\n";
            $output .= "</div></form>\n";
        }
	if ($this->commentary) $output .= "<div>{$this->commentary}</div>";

        print $output;
    }

    /**
     * Get form input from _POST
     */
    function get_input_row() {
        $row = array();
        foreach ($this->columns as $field_name => $column) {
            if ($column->editable and $column->editor !== null) {
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
    function action_validate_change($action, &$row) {
        if (!$this->callback->before_change($action, $row, $errors)) {
            $hilight = $this->display_errors("Some of the values you input were invalid. <span style='color: #a00;'>Please correct the following and try again:</span>\n", $errors);
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
    function action_validate_delete(&$row) {
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
     * Get a simple HTML OK box that leads to action=view
     */
    function get_ok_box() {
        $url = new Tableau_URL();
        $url->addQueryString('action', 'view');
        print "<div class='buttonbox'><form><input type='button' onclick='location.href=\"".$url->getURL(true)."\";' value='OK'></form></div>";
    }

    /**
     * Try to update the row with the values found in _POST.
     * On success, print message.
     * On failure, reprint input form with error messages.
     */
    function action_update() {
        $row = $this->get_input_row();
        $entry_key = $row[$this->conn->primary_key];
        if (!$this->action_validate_change('update', $row)) return;

        // Perform update
        $result = $this->conn->update($row, $entry_key);

        // Show the result
        if (!$result->error) {
            print "<p>Updated a row successfully:</p>\n";
            $view = new Tableau_TableView($this->conn, $this->columns,
                                             $this->callback, true);
            $view->filter->set_filter($this->conn->primary_key, '=',
                                      $entry_key);
            print $view->get_table_view();

            // After callbacks
            $this->callback->after_change('update', $row, $errors);
            $this->display_errors(".. but some errors occurred afterwards:", $errors);
            // OK
            print $this->get_ok_box();
        } else {
            print "<p>Failed to update a row: $result->error</p>\n";
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
            print "<p>Deleted successfully.</p>\n";

            // After callbacks
            $this->callback->after_delete($row, $errors);
            $this->display_errors(".. but some errors occurred afterwards:", $errors);

            // OK
            print $this->get_ok_box();
        } else {
            print "<p>Deleting a row failed.</p>\n";
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
        if (!$this->action_validate_change('insert', $row)) return;

        // Perform insert
        $result = $this->conn->insert($row);

        // Show the result
        if (!$result->error) {
            $result = $this->conn->select(
                '*', 'WHERE ' . $this->conn->key_is($result->last_id));
            $row = $result->fetch_assoc();

            print "<p>Inserted a row successfully:</p>\n";
            $view = new Tableau_TableView($this->conn, $this->columns,
                                             $this->callback, true);
            $view->filter->set_filter($this->conn->primary_key, '=',
                                      $row[$this->conn->primary_key]);
            print $view->get_table_view();

            // After callbacks
            $this->callback->after_change('insert', $row, $errors);
            $this->display_errors(".. but some errors occurred afterwards:", $errors);

            // OK
            print $this->get_ok_box();
        } else {
            print "<p>Failed to insert a row: {$result->error}</p>\n";
            $this->action_input($entry_key, $row);
        }
    }
    
    /**
     * Entry point.
     */
    function display() {
        switch (trim(strtolower($_POST['submit']))) {
        case 'update':
        case '<b>update</b>': // Oh, mighty IE, blessed be your bugs
            $this->action_update();
            break;
        case 'delete':
            $this->action_delete();
            break;
        case 'insert':
        case '<b>insert</b>': // And again, IE
            $this->action_insert();
            break;
        default:
            $this->action_input($_GET['id']);
        };
    }
};


//---------------------------------------------------------------------------//
//                                                                           //
// Table viewer                                                              //
//                                                                           //
//---------------------------------------------------------------------------//


//
// --- Sorting table rows ---------------------------------------------------
//

class Tableau_TableSort
{
    var $sort;
    var $sort_sql;

    var $conn;
    var $columns;

    var $max_sort_fields = 3;

    function Tableau_TableSort(&$conn, &$columns,
                               $default_sort = null) {
        $this->columns = $columns;
        $this->conn = $conn;

        $this->sort = array();

        $seen_fields = array();
        
        for ($i = 0; $i < $this->max_sort_fields; ++$i) {
            $field = (string)$_GET["sort_field_{$i}"];
            $dir = (string)$_GET["sort_dir_{$i}"];

            if (!$field) continue;
            if (!in_array($field, $this->conn->fields)) continue;
            if (!in_array($dir, array('0', '1'))) $dir = 0;
            if ($seen_fields[$field]) continue;

            $seen_fields[$field] = true;
            $this->sort[] = array($field, $dir);

        }

        if (!$this->sort) {
            $this->sort = $default_sort;
        }
    }

    /**
     * Formulate an ORDER BY statement based on _GET information.
     */
    function get_sql() {
        $orders = array();
        foreach ($this->sort as $sort) {
            $dir = $sort[1] ? 'DESC' : 'ASC';
            $orders[] = "FIELD(" . $this->conn->table_name . "." . $sort[0] . ", NULL, '') $dir";
            $orders[] = $this->conn->table_name . "." . $sort[0] . " $dir";
        }
        if (count($orders) > 0) {
            return "ORDER BY " . join(", ", $orders);
        } else {
            return "";
        }
    }

    /**
     * Formulate a HTML <table> header
     */
    function get_table_header() {
        $url = new Tableau_URL();
        $i = 1;
        foreach ($this->sort as $sort) {
            $url->addQueryString("sort_field_{$i}", $sort[0]);
            $url->addQueryString("sort_dir_{$i}", $sort[1]);
            ++$i;
        }
        
        $output = "";
        foreach ($this->columns as $field_name => $column) {
            if (!$column->visible) continue;
            $url->addQueryString('sort_field_0', $field_name);
            if ($this->sort[0][0] == $field_name) {
                if ($this->sort[0][1]) {
                    $ch = " &uarr;";
                    $url->addQueryString('sort_dir_0', 0);
                } else {
                    $ch = " &darr;";
                    $url->addQueryString('sort_dir_0', 1);
                }
            } else {
                $ch = "";
                $url->addQueryString('sort_dir_0', 0);
            }
            $output .= "<th class='header'><a href=\"" . $url->getURL(true) . "\">{$column->name}</a>$ch</th>\n";
        }
        return $output;
    }
};


//
// --- Filtering table rows -------------------------------------------------
//

class Tableau_TableFilter
{
    var $filters;
    var $operator;
    
    var $conn;
    var $columns;

    var $min_nsearch = 1;
    var $max_nsearch = 20;
    var $nsearch;

    /**
     * Parse filter stuff from _GET
     */
    function Tableau_TableFilter(&$conn, &$columns,
                                    $default_filters=null,
                                    $default_filter_mode=null) {
        $this->conn = $conn;
        $this->columns = $columns;

        $this->nsearch = $this->min_nsearch;
        $this->filters = array();

        if ($_GET['nsearch'] >= $this->min_nsearch and
            $_GET['nsearch'] <= $this->max_nsearch)
            $this->nsearch = $_GET['nsearch'];

        for ($i = 0; $i < $this->max_nsearch; ++$i) {
            $field = $_GET["search_{$i}_field"];
            $type = $_GET["search_{$i}_type"];
            $value = $_GET["search_{$i}_value"];

            if (!$type or !$field) continue;

            if (!in_array((string)$field, $this->conn->fields)
                and $field != '*') continue;
            if (!in_array((string)$type, array('LIKE', '>', '<', '=')))
                continue;

            $this->filters[] = array($field, $type, $value);
        }

        if ($_GET['search_or'] == null) {
            $_GET['search_or'] = $default_filter_mode;
        }
        
        if ($_GET['search_or']) {
            $this->operator = ' OR ';
        } else {
            $this->operator = ' AND ';
        }

        if (!$this->filters and $default_filters) {
            $this->filters = $default_filters;
            $this->nsearch = count($this->filters) + 1;
        }

        if ($this->nsearch < count($this->filters))
            $this->nsearch = count($this->filters);
    }

    /**
     * Set a single filters
     */
    function set_filter($field_name, $operation, $value) {
        $this->filters = array(array($field_name, $operation, $value));
    }

    /**
     * Formulate a WHERE statement based on _GET information.
     */
    function get_sql() {
        if (!$this->filters) return;
        
        $searches = array();
        foreach ($this->filters as $filter) {
            $ch = '';
            if ($filter[1] == 'LIKE') $ch = '%';

            if ($filter[0] == '*') {
                $fields = $this->conn->fields;
            } else {
                $fields = array($filter[0]);
            }

            $subsearches = array();
            foreach ($fields as $field) {
                $subsearches[] = "LOWER("
                    . $this->conn->table_name . "."
                    . $this->conn->escape($field) . ") "
                    . $filter[1] . " LOWER('$ch" .
                    $this->conn->escape($filter[2])
                    . "$ch')";
            }
            $searches[] = "(" . join(" OR ", $subsearches) . ")";
        }
        if (!$searches) return "";
        return "WHERE " . join($this->operator, $searches);
    }

    /**
     * Generate a HTML filter input box.
     */
    function get_html() {
        $output = "<form method='get'><div>\n";

        $output .= "<table>\n";

        $fields = array();
        foreach ($this->columns as $field_name => $column) {
            $fields[$field_name] = $column->name;
        }
        $fields['*'] = '* (ANY FIELD)';

        $choices = array("LIKE" => "contains",
                         "=" => "is",
                         ">" => "is greater/later than",
                         "<" => "is smaller/earlier than");

        for ($i = 0; $i < $this->nsearch; ++$i) {
            $output .= "<tr><td>";
            if ($i == 0) {
                $output .= create_select_form(
                    "search_or", true, array('0' => "Match all",
                                             '1' => "Match any"),
                    '', $this->operator == ' OR ' ? 1 : 0, false);
            }
            $output .= "</td>\n";
            
            $search = array('', 'LIKE', '');
            if ($this->filters[$i]) $search = $this->filters[$i];

            $output .= "<td>";
            $output .= create_select_form("search_{$i}_field",
                                          true, $fields, "", $search[0],
                                          true);
            $output .= "</td>\n<td>";
            $output .= create_select_form("search_{$i}_type",
                                          true, $choices, "LIKE", $search[1]);
            $output .= "</td>\n<td>";
            $output .= "  <input type='text' name='search_{$i}_value' value='{$search[2]}'>\n";
            $output .= "</td>\n</tr>\n";
        }

        $output .= "</table></div>\n";

        foreach ($_GET as $key => $value) {
            if (!preg_match('/^search_/', $key)) {
                $output .= "<input type='hidden' name='{$key}' value='{$value}'>";
            }
        }
        
        $output .= "<div class='buttonbox'>";
        $output .= "<input type='submit' name='search_submit' value='Filter'> ";

        // Clear button
        $url = new Tableau_URL();
        $this->cleanup_url($url);
        $output .= "<input type='button' onclick='location.href=\"".$url->getURL(true)."\";' value='Clear'> ";

        // Adjusting number of fields
        $url = new Tableau_URL();
        if ($this->nsearch < $this->max_nsearch) {
            $url->addQueryString('nsearch', $this->nsearch + 1);
            $output .= "<input type='button' onclick='location.href=\"".$url->getURL(true)."\";' value='+'> ";
        }
        if ($this->nsearch > $this->min_nsearch) {
            $url->addQueryString('nsearch', $this->nsearch - 1);
            $output .= "<input type='button' onclick='location.href=\"".$url->getURL(true)."\";' value='-'> ";
        }
        $output .= "</div></form>\n";
        
        return $output;
    }

    function cleanup_url(&$url) {
        for ($i = 0; $i < 50; ++$i) {
            $url->removeQueryString("search_{$i}_field");
            $url->removeQueryString("search_{$i}_type");
            $url->removeQueryString("search_{$i}_value");
        }
        $url->removeQueryString("search_or");
        $url->removeQueryString("search_submit");
    }
};


//
// --- Table view -----------------------------------------------------------
//

/**
 * Display a table.
 */
class Tableau_TableView
{
    var $conn;
    var $columns;
    var $callback;

    var $sort;

    var $filter;
    var $filter_sql;
    var $filters;

    var $limit_offset;
    var $limit_maxrows;

    function Tableau_TableView($conn, &$columns, &$callback, $simple=false,
                               $default_sort=null,
                               $default_filters=null,
                               $default_filter_mode=null) {
        $this->conn = $conn;
        $this->columns = $columns;
        $this->callback = $callback;
        $this->filter = new Tableau_TableFilter($conn, $columns,
                                                $default_filters,
                                                $default_filter_mode);
        $this->sort = new Tableau_TableSort($conn, $columns,
                                            $default_sort);
        $this->simple = $simple;

        $this->limit_maxrows = 1000;
        $this->limit_offset = 0;

        /* Parse offset */
        if ($_GET['offset']) {
            $this->limit_offset = $_GET['offset'];
        }
    }

    /**
     * Return a view of the table, using the current sort+filter settings.
     */
    function get_table_view() {
        // Count visible rows first
        $query = "SELECT COUNT(*) FROM {$this->conn->table_name} " . $this->filter->get_sql() . ";";
        $result = $this->conn->query($query);
        $item = $result->fetch_array();
        $count = $item[0];

        // Adjust limits
        if ($count < $this->limit_maxrows) $this->limit_offset = 0;
        $this->limit_offset = max(0, min($count-1, $this->limit_offset));
        $last_row = min($this->limit_offset + $this->limit_maxrows, $count);

        // Then get the rows
        $query = "SELECT * FROM {$this->conn->table_name} " . $this->filter->get_sql() . " " . $this->sort->get_sql() . " LIMIT {$this->limit_offset}, {$this->limit_maxrows};";
        $result = $this->conn->query($query);

        if ($result->error) {
            return "<p>$query: $result->error</p>\n";
        }
        
        $output = "";

        if (!$this->simple) {
            $output .= "<div class='searchbox'>" . $this->filter->get_html() . "</div>\n";
        }
        
        $output .= "<div class='resultbox'>";

        if ($last_row != $count or $this->limit_offset != 0) {
            $output .= "<div class='navigaterows'>";

            $output .= "<span class='label'>Showing rows " . ($this->limit_offset + 1) . " &ndash; {$last_row} (of {$count})</span>  ";

            $url = new Tableau_URL();
            $newoffset = max($this->limit_offset - $this->limit_maxrows, 0);
            if ($newoffset != $this->limit_offset) {
                $url->addQueryString('offset', $newoffset);
                $output .= "<span class='left'><a href=\"" . $url->getURL('left') . "\">&laquo;&laquo; Previous</a></span> ";
            }
            
            $newoffset = min($this->limit_offset + $this->limit_maxrows,
                             $count - 1);
            if ($newoffset != $this->limit_offset) {
                $url->addQueryString('offset', $newoffset);
                $output .= "<span class='right'><a href=\"" . $url->getURL('left') . "\">Next &raquo;&raquo;</a></span> ";
            }
                
            $output .= "</div>";
        }
        
        $output .= "<table>\n<tr><th></th>\n";
        $output .= $this->sort->get_table_header();
        $output .= "</tr>\n";
        
        $url = new Tableau_URL();
        $url->addQueryString('action', 'edit');
        
        while ($row = $result->fetch_assoc()) {
            $url->addQueryString('id', $row[$this->conn->primary_key]);

            $row_str = "";
            $row_str .= do_format_cell(
                $this->callback, $row, null,
                "<a href=\"" . $url->getURL(true) . "\">&raquo;</a>");
            
            foreach ($this->columns as $field_name => $column) {
                if (!$column->visible) continue;
                $value = $row[$field_name];
                $row_str .= do_format_cell($this->callback, $row, $field_name,
                                          $column->display->get($value));
            }

            $row_attr = array();
            $row_attr['class'] = "datarow";
            $row_attr['ondblclick'] = "location.href='" . $url->getURL(true) . "';";
            $output .= do_format_row($this->callback, $row, $row_str,
                                     $row_attr);
            $output .= "</tr>\n";
        }

        $output .= "</table></div>\n";
        
        if (!$this->simple) {
            $output .= "<div class='querybox'>Query: " . htmlentities($query) .  "</div>\n";
        }

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
//                                                                           //
// Programmer API & controller                                               //
//                                                                           //
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
class Tableau
{
    var $conn;
    var $columns;
    var $callback;
    var $commentary;
    
    var $default_sort = array();
    var $default_filters = array(array(), null);
    
    function Tableau($connection, $table_name) {
        $this->conn = new Tableau_DBTable($connection, $table_name);
        $this->columns = array();
        $this->callback = new Tableau_Callback($columns);
	$this->commentary = "";
    }

    function set_columns() {
        $columns = fold_list_to_map(func_get_args());
        
        foreach ($columns as $id => $value) {
	    if (is_array($value)) {
	        $item = $value[0];
	        if (count($value) >= 1) $item->name = $value[1];
		if (count($value) >= 2) $item->comment = $value[2];
		$columns[$id] = $item;
	    } else {
                if (!$value->name) $columns[$id]->name = $id;
	    }
            $columns[$id]->field_name = $id;
        }
        $this->columns = $columns;
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

    function set_default_sort($field, $dir=0) {
        $this->default_sort = array(array($field, $dir));
    }

    function add_default_sort($field, $dir=0) {
        array_unshift($this->default_sort, array($field, $dir));
    }

    function set_default_filters($filters, $filter_or=false) {
        $this->default_filters = array($filters, $filter_or);
    }

    function set_commentary($commentary) {
        $this->commentary = $commentary;
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
        case 'display_row':
            $this->callback->display_row_callbacks[] = $cb;
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
                //print "POST: \"$key\" => \"$value\"<br>";
                $_POST[$key] = stripslashes($value);
            }
            foreach ($_GET as $key => $value) {
                //print "GET: \"$key\" => \"$value\"<br>";
                $_GET[$key] = stripslashes($value);
            }
            foreach ($_REQUEST as $key => $value) {
                //print "REQUEST: \"$key\" => \"$value\"<br>";
                $_REQUEST[$key] = stripslashes($value);
            }
        }

        $this->callback->columns = &$this->columns;

        // Navigation links
        $url = new Tableau_URL();
        Tableau_TableFilter::cleanup_url($url);
        $url->removeQueryString('id');
        $url->addQueryString('action', 'view');
        print "<div class='linkbox'>";
        print "<span><a href=\"".$url->getURL(true)."\">View all</a></span>";
        $url->addQueryString('action', 'edit');
        print "<span><a href=\"".$url->getURL(true)."\">Insert new</a></span>";
        print "</div>\n";

        // Primary key must always be present and editable
        if (!$this->columns[$this->conn->primary_key]) {
            $this->columns[$this->conn->primary_key]
                = new Tableau_TextColumn();
            $this->columns[$this->conn->primary_key]->name
                = $this->conn->primary_key;
        }
        $this->columns[$this->conn->primary_key]->editable = true;

        // Table view / editor
        switch (trim(strtolower($_GET['action']))) {
        case null:
        case 'view':
            $view = new Tableau_TableView($this->conn, $this->columns,
                                             $this->callback, false,
                                             $this->default_sort,
                                             $this->default_filters[0],
                                             $this->default_filters[1]);
            print "<div class='viewbox'>";
            $view->display();
            print "</div>\n";
            break;
        case 'edit':
            $view = new Tableau_TableEdit($this->conn, $this->columns,
                                          $this->callback, $this->commentary);
            print "<div class='editbox'>";
            $view->display();
            print "</div>\n";
            break;
        }
    }
};


//---------------------------------------------------------------------------//
//                                                                           //
// Cell renderers and editors                                                //
//                                                                           //
//---------------------------------------------------------------------------//


//
// --- Text columns ---------------------------------------------------------
//

class Tableau_TextEditor extends Tableau_Editor
{
    var $rows;
    var $cols;
    
    function Tableau_TextEditor($rows=-1, $cols=-1) {
        if ($rows <= 0) $rows = 1;
        if ($cols <= 0) $cols = 40;
        $this->rows = $rows;
        $this->cols = $cols;
    }
    
    function get_form($prefix, $value) {
        if ($this->rows == 1) {
            return "<input name=\"{$prefix}_text\" type=text size={$this->cols} value=\"$value\">\n";
        } else {
            return "<textarea name=\"{$prefix}_text\" rows={$this->rows} cols={$this->cols}>$value</textarea>\n";
        }
    }

    function get_value($prefix) {
        return $_POST["{$prefix}_text"];
    }
};

class Tableau_TextDisplay extends Tableau_Display
{
    function get($value) {
        return htmlentities($value);
    }
};

class Tableau_TextColumn extends Tableau_Column
{
    function Tableau_TextColumn($rows=-1, $cols=-1) {
        Tableau_Column::Tableau_Column();
        $this->editor = new Tableau_TextEditor($rows, $cols);
        $this->display = new Tableau_TextDisplay();
    }
};


//
// --- Number columns -------------------------------------------------------
//

/** Text editor that normalizes commas to periods */
class Tableau_NumberEditor extends Tableau_Editor
{
    var $unit;

    function Tableau_NumberEditor($unit) {
        Tableau_Editor::Tableau_Editor();
        $this->unit = $unit;
    }

    function get_form($prefix, $value) {
        $value = str_replace('.', ',', (string)$value);
        return "<input name=\"{$prefix}_text\" type=text size=20 value=\"$value\">&nbsp;" . $this->unit . "\n";
    }

    function get_value($prefix) {
        $str = $_POST["{$prefix}_text"];
        $str = str_replace(',', '.', $str); // Normalize comma
	if ($str == '') return null;
	return $str;
    }
};

class Tableau_NumberDisplay extends Tableau_Display
{
    var $unit;

    function Tableau_NumberDisplay($unit) {
        Tableau_Display::Tableau_Display();
        $this->unit = $unit;
    }
    
    function get($value) {
        if ($value != '') {
            $value = str_replace('.', ',', (string)$value);
            return htmlentities($value) . "&nbsp;" . $this->unit;
	} else {
	    return '';
	}
    }
};

class Tableau_NumberColumn extends Tableau_Column
{
    function Tableau_NumberColumn($unit='') {
        Tableau_Column::Tableau_Column();
        $this->editor = new Tableau_NumberEditor($unit);
        $this->display = new Tableau_NumberDisplay($unit);
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

function parse_time($string) {
    if (preg_match('/^([0-9]+):([0-9]+):([0-9]+)$/', $string, $matches)) {
        return array($matches[1], $matches[2], $matches[3]);
    } elseif (preg_match('/^([0-9]+):([0-9]+)$/', $string, $matches)) {
        return array($matches[1], $matches[2], 0);
    } else {
        return array(null, null, null);
    }
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

function create_select_form($name, $is_map, $values, $options, $selected,
                            $add_empty = true) {
    $selected_seen = false;
    $form = "<select name=\"$name\" id=\"$name\" $options>\n";
    if ($add_empty) {
        $form .= "  <option value=\"\"></option>\n";
    }
    foreach ($values as $key => $value) {
        if (!$is_map) $key = $value;
        
        $form .= "  <option value=\"$key\"";
        if ((string)$selected == (string)$key) {
            $form .= " selected>";
	    $selected_seen = true;
        } else {
            $form .= ">";
        }
        $form .= "$value</option>\n";
    }
    if ($selected != null && !$selected_seen) {
        $form .= "  <option value=\"$value\" selected>$value</option>\n";
    }
    $form .= "</select>\n";
    return $form;
}

function format_range($fmt, $min, $max, $step=1) {
    $value = array();
    for ($i = 0; $i <= $max; $i += $step) {
        $value[] = sprintf($fmt, $i);
    }
    return $value;
}

function get_jscalendar($prefix, $has_time, $value, $max_year) {
    if (!$has_time) {
        $code = <<<__EOF__
<input type=hidden name="{$prefix}_jscalendar" id="${prefix}_jscalendar" value="$value">
<input id="{$prefix}_trigger" type=button value='Select'>
<script type="text/javascript">
    function catcalc_{$prefix}(cal) {
        var date = cal.date;

        var field_y = document.getElementById("{$prefix}_year");
        var field_m = document.getElementById("{$prefix}_month");
        var field_d = document.getElementById("{$prefix}_day");

        field_m.selectedIndex = date.print("%m");
        field_d.selectedIndex = date.print("%d");
        field_y.selectedIndex = 1 + {$max_year} - date.print("%Y");
    }
    Calendar.setup({
        inputField     :    "${prefix}_jscalendar", // id of the input field
        ifFormat       :    "%Y-%m-%d", // format of the input field
        showsTime      :    false,
        timeFormat     :    "24",
        button         :    "{$prefix}_trigger",
        onUpdate       :    catcalc_{$prefix},
        singleclick    :    true
    });
</script>
__EOF__;
    } else {
        $code = "";
    }

    return $code;
}
    
class Tableau_DateEditor extends Tableau_Editor
{
    var $months = array(1 => 'Jan',  2 => 'Feb',  3 => 'Mar', 4 => 'Apr',
                        5 => 'May',  6 => 'Jun',  7 => 'Jul', 8 => 'Aug',
                        9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec');
    var $min_year;
    var $max_year;

    function Tableau_DateEditor($min_year=null, $max_year=null) {
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
        $form .= get_jscalendar($prefix, false, $value, $year_range[0]);
        return $form;
    }

    function get_value($prefix) {
        $year = $_POST["{$prefix}_year"];
        $month = $_POST["{$prefix}_month"];
        $day = $_POST["{$prefix}_day"];
        return format_date(array($year, $month, $day));
    }
};

class Tableau_DateColumn extends Tableau_TextColumn
{
    function Tableau_DateColumn() {
        Tableau_TextColumn::Tableau_TextColumn();
        $this->editor = new Tableau_DateEditor();
        $this->display = new Tableau_TextDisplay();
    }

    function set_year_range($min_year, $max_year) {
        $this->editor->set_year_range($min_year, $max_year);
    }
};

class Tableau_DateTimeEditor extends Tableau_DateEditor
{
    function Tableau_DateTimeEditor() {
        Tableau_DateEditor::Tableau_DateEditor();
    }

    function get_form($prefix, $value) {
        $form = Tableau_DateEditor::get_form($prefix, $value);

        $date = parse_datetime($value);

        $form .= ", ";
        $form .= create_select_form("{$prefix}_hour",
                                    false, format_range("%2d", 0, 23),
                                    "", $date[3]);
        $form .= ":";
        $form .= create_select_form("{$prefix}_min",
                                    false, format_range("%02d", 0, 59),
                                    "", $date[4]);
        $form .= ":";
        $form .= create_select_form("{$prefix}_sec",
                                    false, format_range("%02d", 0, 59),
                                    "", $date[5]);

        $form .= get_jscalendar($prefix, true, $value);
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

class Tableau_TimeEditor extends Tableau_Editor
{
    function Tableau_TimeEditor($min_step=1, $start_hour=0, $end_hour=23) {
        Tableau_Editor::Tableau_Editor();
	$this->min_step = $min_step;
	$this->start_hour = $start_hour;
	$this->end_hour = $end_hour;
    }

    function get_form($prefix, $value) {
        $time = parse_time($value);

        $form = "";
        $form .= create_select_form("{$prefix}_hour",
                                    false, format_range("%2d", $this->start_hour, $this->end_hour),
                                    "", $time[0]);
        $form .= ":";
        $form .= create_select_form("{$prefix}_min",
                                    false, format_range("%02d", 0, 59, $this->min_step),
                                    "", $time[1]);
        return $form;
    }

    function get_value($prefix) {
        $hour = $_POST["{$prefix}_hour"];
        $min = $_POST["{$prefix}_min"];
	return sprintf("%02d:%02d", $hour, $min);
    }
};

class Tableau_DateTimeColumn extends Tableau_DateColumn
{
    function Tableau_DateTimeColumn() {
        Tableau_TextColumn::Tableau_TextColumn();
        $this->editor = new Tableau_DateTimeEditor();
        $this->display = new Tableau_TextDisplay();
    }
};

class Tableau_TimeColumn extends Tableau_TextColumn
{
    function Tableau_TimeColumn($min_step=1, $start_hour=0, $end_hour=23) {
        Tableau_TextColumn::Tableau_TextColumn();
        $this->editor = new Tableau_TimeEditor($min_step, $start_hour, $end_hour);
        $this->display = new Tableau_TextDisplay();
    }
};


//
// --- ID columns -----------------------------------------------------------
//

class Tableau_IDDisplay extends Tableau_TextDisplay
{
    function get($value) {
        if ($value) {
            return "<span style='color: #aaa;'>$value</span>";
        } else {
            return "<span style='color: #aaa;'>[new]</span>";
        }
    }
};

class Tableau_IDEditor extends Tableau_Editor
{
    function get_form($prefix, $value) {
        return "<input name=\"{$prefix}_id\" type='hidden' value=\"$value\">" . Tableau_IDDisplay::get($value);
    }
    function get_value($prefix) {
        return $_POST[$prefix . '_id'];
    }
};

class Tableau_IDColumn extends Tableau_TextColumn
{
    function Tableau_IDColumn() {
        Tableau_TextColumn::Tableau_TextColumn();
        $this->editor = new Tableau_IDEditor();
        $this->display = new Tableau_IDDisplay();
    }
};


//
// --- Last updated column --------------------------------------------------
//

class Tableau_LastUpdatedEditor extends Tableau_Editor
{
    function get_form($prefix, $value) {
        return htmlentities($value);
    }
    function get_value($prefix) {
        return date('Y-m-d H:i:s');
    }
};

class Tableau_LastUpdatedColumn extends Tableau_Column
{
    function Tableau_LastUpdatedColumn() {
        Tableau_Column::Tableau_Column();
        $this->editor = new Tableau_LastUpdatedEditor();
        $this->display = new Tableau_TextDisplay();
    }
}


//
// --- Choice columns -------------------------------------------------------
//

class Tableau_ChoiceEditor extends Tableau_Editor
{
    var $choices;
    var $is_map;
    
    function Tableau_ChoiceEditor($choices, $is_map = false) {
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

class Tableau_ChoiceDisplay extends Tableau_Display
{
    var $choices;
    var $is_map;

    function Tableau_ChoiceDisplay($choices, $is_map) {
        $this->choices = $choices;
        $this->is_map = $is_map;
    }
    
    function get($value) {
        if ($this->is_map and $this->choices[$value] != '') {
            return $this->choices[$value];
        } else {
            return $value;
        }
    }
};

class Tableau_ChoiceColumn extends Tableau_TextColumn
{
    function Tableau_ChoiceColumn($choices, $is_map = false) {
        Tableau_TextColumn::Tableau_TextColumn();
        $this->editor = new Tableau_ChoiceEditor($choices, $is_map);
        $this->display = new Tableau_ChoiceDisplay($choices, $is_map);
    }

    function set_choices($choices, $is_map=false) {
        $this->editor->choices = $choices;
        $this->editor->is_map = $is_map;
        $this->display->choices = $choices;
        $this->display->is_map = $is_map;
    }
};


//
// --- Combo choice columns ---------------------------------------------------
//

class Tableau_ComboChoiceEditor extends Tableau_ChoiceEditor
{
    function get_form($prefix, $value) {
        $form = create_select_form("{$prefix}_choice",
                                   $this->is_map, $this->choices,
                                   "onchange='process_choice_{$prefix}(this, document.getElementById(\"{$prefix}_text\"))'", $value);
        if ( $this->is_map and !$this->choices[$value] or
	    !$this->is_map and !in_array((string)$value, $this->choices)) {
            $textval = $value;
	} else {
	    $textval = ""; 
	}
        $form .= "<input type=\"text\" name=\"{$prefix}_text\" id=\"{$prefix}_text\" value=\"$textval\" size=40>";

        $form .= <<<__EOF__
<script type="text/javascript" language="JavaScript">
function process_choice_{$prefix}(selection, textfield) {
  if (selection.selectedIndex == 0) {
    textfield.disabled = false;
    textfield.focus();
  } else {
    textfield.disabled = true;
    textfield.blur();
  }
}

textfield = document.getElementById("{$prefix}_text");
selection = document.getElementById("{$prefix}_choice");
if (selection.selectedIndex != 0) {
    textfield.disabled = true;
    textfield.blur();
}        
</script>
__EOF__;
        return $form;
    }

    function get_value($prefix) {
        if (!$_POST["{$prefix}_choice"]) {
            return $_POST["{$prefix}_text"];
        } else {
            return $_POST["{$prefix}_choice"];
        }
    }
};

class Tableau_ComboChoiceColumn extends Tableau_TextColumn
{
    function Tableau_ComboChoiceColumn($choices, $is_map = false) {
        Tableau_TextColumn::Tableau_TextColumn();
        $this->editor = new Tableau_ComboChoiceEditor($choices, $is_map);
        $this->display = new Tableau_ChoiceDisplay($choices, $is_map);
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

class Tableau_ForeignKeyColumn extends Tableau_ChoiceColumn
{
    function Tableau_ForeignKeyColumn($connection, $table,
                                      $key_field, $value_field, $query='') {
        $choices = $this->get_choices($connection, $table, $key_field,
                                      $value_field, $query);
        Tableau_ChoiceColumn::Tableau_ChoiceColumn(
            $choices, $key_field != $value_field);
    }

    /* @static */
    function get_choices($connection, $table, $key_field, $value_field,
                         $query='') {
        $db = new Tableau_DBTable($connection, $table);

        if (!$query) {
            $query = "SELECT " . $table . "." . $key_field . ", "
                . $table . "." . $value_field . " FROM {$table};";
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
        natcasesort($choices);
        return $choices;
    }
};


//
// --- Foreign key Combo columns ----------------------------------------------
//

class Tableau_ForeignKeyComboColumn extends Tableau_ComboChoiceColumn
{
    function Tableau_ForeignKeyComboColumn($connection, $table,
                                           $key_field, $value_field,
                                           $query='') {
        $choices = Tableau_ForeignKeyColumn::get_choices(
            $connection, $table, $key_field, $value_field, $query);
        Tableau_ComboChoiceColumn::Tableau_ComboChoiceColumn($choices,$is_map);
    }
};


//
// --- File storage columns ---------------------------------------------------
//

/** Generate a unique file name */
function tableau_default_file_name_generator($directory, $extension, $row) {
    $prefix = "";

    if ($row['name']) $prefix = $row['name'];
    if ($row['id']) $prefix = $row['id'];
    if ($row['ID']) $prefix = $row['ID'];

    if ($prefix != "" and !file_exists($directory+"/"+$prefix+$extension)) {
        return $prefix . $extension;
    } else {
        if ($prefix != "") $prefix .= "_";
        while (1) {
            $filename = tempnam($directory, $prefix) + $extension;
            if (file_exists($filename)) continue;
            $filename = substr($filename, strlen($directory)+1);
            break;
        };
        return $filename;
    }
}

class Tableau_FileEditor extends Tableau_Editor
{
    function get_form($prefix, $value) {
        return "<input name='{$prefix}_key' type=hidden value=\"$value\">
                <input name='{$prefix}_file' type=file>\n";
    }

    function get_value($prefix) {
        return $_POST["{$prefix}_key"];
    }
};

class Tableau_FileDisplay extends Tableau_Display
{
    function Tableau_FileDisplay($www_directory=null) {
        Tableau_Display::Tableau_Display();
        $this->www_directory = $www_directory;
    }
    
    function get($value) {
        if ($this->www_directory) {
            if (preg_match("/\\.(png|jpg|gif|jpeg)$/", $value)) {
                return "<a href='$this->www_directory/$value'><img src='$this->www_directory/$value' alt='[Image]'></a>";
            } else {
                return "<a href='$this->www_directory/$value'><b>[File]</b></a>";
            }
        } else {
            return "<b>[File]</b>";
        }
        return "<b>[File]</b>";
    }
};

class Tableau_FileColumn extends Tableau_Column
{
    function Tableau_FileColumn($directory,
                                $www_directory=null,
                                $name_generator=null) {
        Tableau_Column::Tableau_Column();

        // strip terminal slash
        preg_match("/^(.*?)\\/*$/", $directory, $matches);
        $directory = $matches[1];

        // proceed
        $this->directory = $directory;
        if (!$directory) die("No directory for Tableau_FileColumn");
        if ($name_generator != null) {
            $this->name_generator = $name_generator;
        } else {
            $this->name_generator = tableau_default_file_name_generator;
        }

        // proceed
        $this->editor = new Tableau_FileEditor();
        $this->display = new Tableau_FileDisplay($www_directory);
    }

    function validate_value($action, &$value, &$msg, $row) {
        $key = "edit_{$this->field_name}_file";

        if (!$_FILES[$key]['name']) {
            // no file given
            $value = null;
            return Tableau_Column::validate_value($value, $msg, $row);
        }

        if ($_FILES[$key]['size'] <= 0) {
            $size = $_FILES[$key]['size'];
            $msg = "Empty file uploaded: $size";
            return false;
        }

        if (!is_uploaded_file($_FILES[$key]['tmp_name']))
        {
            $msg = "Invalid upload for: " . $_FILES[$key]['tmp_name']
                . "<br>" . $_FILES[$key]['error'];
            return false;
        }

        // Snarf extension
        $extension = "";
        if (preg_match("/\\.([^.]+)$/", $_FILES[$key]['name'], $matches)) {
            $extension = "." . $matches[1];
        }

        // Check if extension was changed...
        if ($value) {
            $old_extension = "";
            $old_stem = $value;
            if (preg_match("/^(.*)\\.([^.]+)$/", $value, $matches)) {
                $old_stem = $matches[1];
                $old_extension = "." . $matches[2];
            }

            if ($extension != $old_extension) {
                $value = $old_stem . $extension;
            }
        }

        // Generate name, if missing
        if (!$value) {
            $cb = $this->name_generator;
            $value = $cb($this->directory, $extension, $row);
            if (!$value) {
                $msg = "File name generation failed.";
                return false;
            }
        }

        // Strip slashes
        $value = preg_replace("/\\//", "_", $value);

        return Tableau_Column::validate_value($value, $msg, $row);
    }
    
    function finalize_value($action, &$value, &$msg, $row) {
        $key = "edit_{$this->field_name}_file";

        if (!$value) return true;

        // Strip slashes
        $value = preg_replace("/\\//", "_", $value);

        // Move file
        $dest = $this->directory . "/" . ((string)$value);
        if (!move_uploaded_file($_FILES[$key]['tmp_name'], $dest)) {
            $msg = "Error moving file.";
            return false;
        }
        return true;
    }
};


//---------------------------------------------------------------------------//
//                                                                           //
// Utilities                                                                 //
//                                                                           //
//---------------------------------------------------------------------------//



/**
 * This part comes from Richard Heyes's (http://www.phpguru.org/)
 * TableEditor. (Also under GPL, but probably doesn't matter for snippets
 * of this size.)
 *
 * Necessary to allow translation of ampersands to their entity equivalent.
 * This is due to MSIE replacing &copy= in urls with the copyright symbol,
 * despite the lack of ending semi-colon... :-/
 */
class Tableau_URL extends Net_URL
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
            // This is the key difference to Tableau_URL
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
