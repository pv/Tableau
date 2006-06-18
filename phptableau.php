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
};

/*****************************************************************************
 * Controller
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
        print "Q: $query<br>";
        $result = mysql_query($query, $this->connection);
        if (!$result) {
            die("DB Error ($query): " . mysql_error($this->connection));
        }
        return new DBResult($result);
    }

    function escape($string) {
        return mysql_real_escape_string($string, $this->connection);
    }
};

class DBResult
{
    var $result;

    function DBResult($result) { $this->result = $result; }
    function fetch_assoc() { return mysql_fetch_assoc($this->result); }
    function fetch_object() { return mysql_fetch_object($this->result); }
};


class TableEdit
{
    var $conn;
    var $columns;

    function TableEdit($conn, &$columns) {
        $this->conn = $conn;
        $this->columns = $columns;
    }

    function get_edit_form($row) {
        $output .= "<table>";
        foreach ($this->columns as $field_name => $column) {
            $output .= "<tr>";
            $output .= "<td>{$column->name}</td>\n";
            
            $value = $row[$field_name];
            if ($column->editable and $column->editor) {
                $disp = $column->editor->get_form("edit_$field_name", $value);
                $output .= "<td>{$disp}</td>\n";
            } else {
                $output .= "<td>{$column->display->get($value)}</td>";
            }
            if ($column->comment) {
                $output .= "<td>{$column->comment}</td>";
            }
            $output .= "</tr>";
        }
        $output .= "</table>";
        return $output;
    }

    function action_input($entry_key) {
        $query = sprintf("SELECT * FROM {$this->conn->table_name} WHERE {$this->conn->primary_key} = '%s';", $this->conn->escape($entry_key));
        $result = $this->conn->query($query);

        $values = $result->fetch_assoc();

        $output = "";
        $output .= "<form method='post'>";
        $output .= $this->get_edit_form($values);
        if ($values) {
            $output .= "<input type='submit' name='submit' value='update'>";
            $output .= "<input type='submit' name='submit' value='delete'>";
            $output .= "</form>";
        } else {
            $output .= "<input type='submit' name='submit' value='insert'>";
            $output .= "</form>";
        }

        print $output;
    }

    function get_input_values() {
        $values = array();
        foreach ($this->columns as $field_name => $column) {
            if ($column->editable and $column->editor) {
                $values[$field_name] = $column->editor->get_value("edit_$field_name");
                if (!$column->validate_value($values[$field_name], $msg)) {
                    die("Invalid value '{$values[$field_name]}' for {$field_name}: {$msg}");
                }
            }
        }
        return $values;
    }

    function action_update($entry_key) {
        $values = $this->get_input_values();
        $selection = "{$this->conn->primary_key} = '{$this->conn->escape($entry_key)}'";
        $assign = array();
        foreach ($values as $key => $value) {
            $assign[] = "$key = '{$this->conn->escape($value)}'";
        }
        $values[] = $selection;
        $query = "UPDATE {$this->conn->table_name} SET " . join(", ", $assign) . " WHERE $selection;";
        $result = $this->conn->query($query);
        print "UPDATE";
        

    }

    function action_delete($entry_key) {
        print "DELETE";
        $selection = "{$this->conn->primary_key} = '{$this->conn->escape($entry_key)}'";
        $query = "DELETE FROM {$this->conn->table_name} WHERE $selection;";
        $result = $this->conn->query($query);
        print "DELETE";
    }

    function action_insert($entry_key) {
        $values = $this->get_input_values();
        $fields = array();
        $field_values = array();
        foreach ($this->columns as $field_name => $column) {
            if ($column->editable and $column->editor) {
                $fields[] = $field_name;
                $field_values[] = "'{$this->conn->escape($column->editor->get_value("edit_$field_name"))}'";;
            }
        }
        
        $query = "INSERT INTO {$this->conn->table_name} (" . join(",", $fields) . ") VALUES (" . join(",", $field_values) . ");";
        $result = $this->conn->query($query);
        print "INSERT";
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

    function TableView($conn, &$columns) {
        $this->conn = $conn;
        $this->columns = $columns;
    }

    function get_table_view() {
        $query = "SELECT * FROM {$this->conn->table_name};";
        $result = $this->conn->query($query);
        
        $output = "";

        $output .= "<table><tr><th></th>";
        foreach ($this->columns as $field_name => $column) {
            if (!$column->visible) continue;
            $output .= "<th>{$field_name}</th>";
        }
        $output .= "</tr>";
        
        while ($row = $result->fetch_assoc()) {
            $output .= "<tr>";

            $edit_url = new My_URL();
            $edit_url->addQueryString('action', 'edit');
            $edit_url->addQueryString('id', $row[$this->conn->primary_key]);
            
            $output .= "<td><a href=\"{$edit_url->getURL(true)}\">X</a></td>";
            
            foreach ($this->columns as $field_name => $column) {
                if (!$column->visible) continue;
                
                $value = $row[$field_name];
                $disp = $column->display->get($value);
                $output .= "<td>{$disp}</td>\n";
            }
            $output .= "</tr>";
        }

        $output .= "</table>";

        $insert_url = new My_URL();
        $insert_url->addQueryString('action', 'edit');
        $output .= "<a href=\"{$insert_url->getURL(true)}\">Insert row</a>";

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
    
    function PhpTableau($connection, $table_name, $encoding) {
        $this->conn = new DBTable($connection, $table_name, $encoding);
        $this->columns = array();
    }

    function set_columns($columns) {
        $this->columns = $columns;
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

    function display() {
        print "<table>";
        foreach ($_REQUEST as $key => $value) {
            print "<tr><td>$key</td><td>$value</td></tr>";
        }
        print "</table>";

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
        
        switch ($_GET['action']) {
        case null:
        case 'view':
            $view = new TableView($this->conn, $this->columns);
            $view->display();
            break;
        case 'edit':
            $view = new TableEdit($this->conn, $this->columns);
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
        return <<<__EOF__
<input name="{$prefix}_text" type=text value="$value">
__EOF__;
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
 * ID columns
 */

class IDDisplay extends TextDisplay
{
    function get($value) {
        return "<span style='color: #aaa;'>$value</span>";
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
