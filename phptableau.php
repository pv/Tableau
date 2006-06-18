<? # -*-php-*-

require_once('Net/URL.php');

/*****************************************************************************
 * Table columns (abstract)
 */

class Editor
{
    function get_form($prefix, $value) {}
    function get_value($prefix) {}
};

class Display
{
    function get($value) {}
};

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
    
    function Column($name, $comment, $visible, $editable, $validators) {
        $this->name = $name;
        $this->comment = $comment;
        $this->visible = $visible;
        $this->editable = $editable;
        $this->validators = $validators;
    }

    function prepare() {}

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

/*****************************************************************************
 * DB interface
 */

class DBTable
{
    var $connection;
    var $table_name;
    var $encoding;
    var $primary_key;
    var $fields;

    function DBTable($connection, $table_name, $encoding) {
        $this->connection = $connection;
        $this->table_name = $table_name;
        $this->encoding = $encoding;
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

class DBResult
{
    var $result;

    function DBResult($result, $connection) {
        $this->result = $result;
        if (!$result) { $this->error = $connection->error(); }
    }
    function fetch_assoc() { return mysql_fetch_assoc($this->result); }
    function fetch_object() { return mysql_fetch_object($this->result); }
};


/*****************************************************************************
 * Controller
 */

class Callback
{
    var $before_change_callbacks;
    var $after_change_callbacks;

    var $before_delete_callbacks;
    var $after_delete_callbacks;

    var $columns;

    function Callback(&$columns) {
        $this->columns = $columns;

        $this->before_change_callbacks = array();
        $this->after_change_callbacks = array();
        
        $this->before_delete_callbacks = array();
        $this->after_delete_callbacks = array();
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
};

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
                $output .= "<td>".$column->display->get($value)."</td>";
            }
            if ($column->comment) {
                $output .= "<td>{$column->comment}</td>";
            }
            $output .= "</tr>";
        }
        $output .= "</table>";
        return $output;
    }

    function action_input($entry_key, $row=null, $hilight=array()) {
        if (!$row) {
            $query = sprintf("SELECT * FROM {$this->conn->table_name} WHERE {$this->conn->primary_key} = '%s';", $this->conn->escape($entry_key));
            $result = $this->conn->query($query);
            $row = $result->fetch_assoc();
        }

        $output = "";
        $output .= "<form method='post'>";
        $output .= $this->get_edit_form($row, $hilight, $row==null);
        if ($row) {
            $output .= "<input type='submit' name='submit' value='update'>";
            $output .= "<input type='submit' name='submit' value='delete'>";
            $output .= "</form>";
        } else {
            $output .= "<input type='submit' name='submit' value='insert'>";
            $output .= "</form>";
        }

        print $output;
    }

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
            $this->action_input($entry_key, $row, $hilight);
            return false;
        }
        return true;
    }

    function action_validate_delete($row) {
        if (!$this->callback->before_delete($row, $errors)) {
            $hilight = $this->display_errors("Failed to delete a row:", $errors);
            $this->action_input($entry_key, $row, $hilight);
            return false;
        }
        return true;
    }

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
     */
    function action_update($entry_key) {
        $row = $this->get_input_row();
        if (!$this->action_validate_change($row)) return;

        // Perform update
        $selection = "{$this->conn->primary_key} = '".$this->conn->escape($entry_key)."'";
        $assign = array();
        foreach ($row as $key => $value) {
            $assign[] = "$key = '".$this->conn->escape($value)."'";
        }
        $assign[] = $selection;
        $query = "UPDATE {$this->conn->table_name} SET " . join(", ", $assign) . " WHERE $selection;";
        $result = $this->conn->query($query);

        // Show the result
        if (!$result->error) {
            print "<p>Updated a row successfully:</p>";
            $view = new TableView($this->conn, $this->columns);
            $view->filter = "WHERE $selection";
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
     * Try to delete the row.
     */
    function action_delete($entry_key) {
        $selection = "{$this->conn->primary_key} = '".$this->conn->escape($entry_key)."'";
        $row = $this->conn->fetch_one_assoc("SELECT * FROM {$this->conn->table_name} WHERE $selection;");

        if (!$this->action_validate_delete($row)) return;

        $query = "DELETE FROM {$this->conn->table_name} WHERE $selection;";
        $result = $this->conn->query($query);

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
     * Try to insert a new row with the values found in _POST.
     */
    function action_insert($entry_key) {
        $row = $this->get_input_row();
        if (!$this->action_validate_change($row)) return;

        // Perform insert
        $fields = array();
        $values = array();
        foreach ($this->columns as $field_name => $column) {
            if ($column->editable and $column->editor) {
                $fields[] = $field_name;
                $values[] = "'".$this->conn->escape($column->editor->get_value("edit_$field_name"))."'";;
            }
        }
        $query = "INSERT INTO {$this->conn->table_name} (" . join(",", $fields) . ") VALUES (" . join(",", $values) . ");";
        $result = $this->conn->query($query);

        // Show the result
        if (!$result->error) {
            $row = $result->fetch_assoc();
            $selection = "{$this->conn->primary_key} = '" . $this->conn->escape($row[$this->conn->primary_key]) . "'";

            print "<p>Inserted a row successfully:</p>";
            $view = new TableView($this->conn, $this->columns);
            $view->filter = "WHERE $selection";
            print $view->get_table_view();
        } else {
            print "<p>Failed to insert a row: {$result->error}</p>";
            $this->action_input($entry_key, $row);
        }
    }
    
    function display() {
        $entry_key = $_GET['id'];
        switch ($_POST['submit']) {
        case 'update':
            $this->action_update($entry_key);
            break;
        case 'delete':
            $this->action_delete($entry_key);
            break;
        case 'insert':
            $this->action_insert($entry_key);
            break;
        default:
            $this->action_input($entry_key);
        };
    }
};

class TableView
{
    var $conn;
    var $columns;
    var $filter;

    function TableView($conn, &$columns) {
        $this->conn = $conn;
        $this->columns = $columns;
    }

    function get_table_view() {
        $query = "SELECT * FROM {$this->conn->table_name} {$this->filter};";
        $result = $this->conn->query($query);
        
        $output = "";

        $output .= "<table><tr><th></th>";
        foreach ($this->columns as $field_name => $column) {
            if (!$column->visible) continue;
            $output .= "<th>{$column->name}</th>";
        }
        $output .= "</tr>";
        
        while ($row = $result->fetch_assoc()) {
            $output .= "<tr>";

            $edit_url = new My_URL();
            $edit_url->addQueryString('action', 'edit');
            $edit_url->addQueryString('id', $row[$this->conn->primary_key]);
            
            $output .= "<td><a href=\"".$edit_url->getURL(true)."\">&raquo;</a></td>";
            
            foreach ($this->columns as $field_name => $column) {
                if (!$column->visible) continue;
                
                $value = $row[$field_name];
                $disp = $column->display->get($value);
                $output .= "<td>{$disp}</td>\n";
            }
            $output .= "</tr>";
        }

        $output .= "</table>";

        return $output;
    }

    function display() {
        print $this->get_table_view();
    }
};

class PhpTableau
{
    var $conn;
    var $columns;
    var $callback;
    
    function PhpTableau($connection, $table_name, $encoding) {
        $this->conn = new DBTable($connection, $table_name, $encoding);
        $this->columns = array();
        $this->callback = new Callback($columns);
    }

    function set_columns($columns) {
        $this->columns = $columns;
        $this->callback->columns = $columns;
    }

    function set_editable($column_names, $editable = true) {
        foreach ($this->columns as $name => $value) {
            $value->editable = $editable;
        }
    }

    function set_visible($column_names, $visible = true) {
        foreach ($this->columns as $name => $value) {
            $value->visible = $visible;
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
        default:
            die("$place is not a valid callback type.");
            break;
        }
    }

    function display() {
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

        $url = new My_URL();
        $url->addQueryString('action', 'view');
        $url->removeQueryString('id');
        print "<a href=\"".$url->getURL(true)."\">View</a>";

        $url->addQueryString('action', 'edit');
        print " <a href=\"".$url->getURL(true)."\">Insert row</a>";

        switch ($_GET['action']) {
        case null:
        case 'view':
            $view = new TableView($this->conn, $this->columns);
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

/*****************************************************************************
 * Text columns
 */

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
    function TextColumn($name, $comment="", $visible=true, $editable=true,
                        $validators=array()) {
        Column::Column($name, $comment, $visible, $editable, $validators);
        $this->editor = new TextEditor();
        $this->display = new TextDisplay();
    }
};


/*****************************************************************************
 * Date columns
 */

function parse_date($string) {
    if (!$string) return array(null, null, null);
    return array(substr($string, 0, 4), substr($string, 5, 2),
                 substr($string, 8, 2));
}

function parse_datetime($string) {
    if (!$string) return array(null, null, null, null, null, null);
    return array(substr($string, 0, 4), substr($string, 5, 2),
                 substr($string, 8, 2), substr($string, 11, 2),
                 substr($string, 14, 2), substr($string, 17, 2));
}

function format_date($ar) {
    return sprintf("%04d-%02d-%02d", $ar[0], $ar[1], $ar[2]);
}

function format_datetime($ar) {
    return sprintf("%04d-%02d-%02d %02d:%02d:%02d",
                   $ar[0], $ar[1], $ar[2], $ar[3], $ar[4], $ar[5]);
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
        $this->set_range($min_year, $max_year);
    }

    function set_range($min_year, $max_year) {
        if (!$min_year) $min_year = date('Y') - 10;
        if (!$max_year) $max_year = date('Y') + 10;
        $this->min_year = $min_year;
        $this->max_year = $max_year;
    }

    function get_form($prefix, $value) {
        $date = parse_date($value);

        if ($date[0] != null and $date[0] < $this->min_year)
            $this->min_year = $date[0];
        if ($date[0] != null and $date[0] > $this->max_year)
            $this->max_year = $date[0];

        $form = "";
        $form .= create_select_form("{$prefix}_year", false,
                                    array_reverse(range($this->min_year, $this->max_year)),
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
    function DateColumn($name, $comment="", $visible=true, $editable=true,
                        $validators=array()) {
        TextColumn::TextColumn($name, $comment, $visible, $editable,
                               $validators);
        $this->editor = new DateEditor();
        $this->display = new TextDisplay();
    }

    function set_range($min_year, $max_year) {
        $this->editor->set_range($min_year, $max_year);
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
    function DateTimeColumn($name, $comment="",
                            $visible=true, $editable=true,
                            $validators=array()) {
        TextColumn::TextColumn($name, $comment, $visible, $editable,
                               $validators);
        $this->editor = new DateTimeEditor();
        $this->display = new TextDisplay();
    }
};

/*****************************************************************************
 * ID columns
 */

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

class IDColumn extends TextColumn
{
    function IDColumn($name, $comment="") {
        TextColumn::TextColumn($name, $comment);
        $this->editor = null;
        $this->display = new IDDisplay();
    }
};


/*****************************************************************************
 * URLs
 */

/**
 * Necessary to allow translation of ampersands to their entity
 * equivalent. Thisis due to MSIE replacing &copy= in urls with the copyright
 * symbol, despite the lack of ending semi-colon... :-/
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
            $querystring = str_replace('&', '&amp;', $querystring); // This is the key difference to TableEditor_URL
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
