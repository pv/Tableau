<? # -*-php-*-

/*****************************************************************************
 * Main tableau
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
    
    function Column($comment = "", $visible = true, $editable = true,
                    $validators = array()) {
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

class PhpTableau
{
    var $connection;
    var $table_name;
    var $encoding;
    var $columns;
    
    function PhpTableau($connection, $table_name, $encoding) {
        $this->connection = $connection;
        $this->table_name = $table_name;
        $this->encoding = $encoding;
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
    
    function action_submit() {
    }

    function action_view() {
        $query = sprintf("SELECT * FROM %s;",
                         mysql_real_escape_string($this->table_name));
        $result = mysql_query($query, $this->connection);
        if (!$result) {
            die("DB error:" . mysql_error($this->connection));
        }
        
        $output = "";

        $output .= "<table><tr>";
        foreach ($this->columns as $field_name => $column) {
            $output .= "<th>$field_name</th>";
        }
        $output .= "</tr>";
        
        while ($row = mysql_fetch_assoc($result)) {
            $output .= "<tr>";
            foreach ($this->columns as $field_name => $column) {
                $value = $row[$field_name];
                $disp = $column->display->get($value);
                $output .= "<td>$disp</td>\n";
            }
            $output .= "</tr>";
        }

        $output .= "</table>";

        print $output;
    }

    function action_edit() {
        
    }

    function display() {
        switch ($_GET['action']) {
        case null:
        case 'view':
            $this->action_view();
            break;
        case 'submit':
            $this->action_submit();
            break;
        case 'edit':
            $this->action_edit();
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
        $form = <<<__EOF__
<input name="$prefix.text" type=text>
__EOF__;
    }

    function get_value($prefix) {
        return $_POST["$prefix.text"];
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

/*****************************************************************************
 * ID columns
 */

class IDDisplay extends TextDisplay
{};

class IDColumn extends TextColumn
{
    function IDColumn() {
        TextColumn::TextColumn();
        $this->editable = false;
        $this->editor = null;
        $this->display = new TextDisplay();
    }
};
