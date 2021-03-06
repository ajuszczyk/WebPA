<?php
/**
 * Class :  Form
 *
 * @copyright Loughborough University
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html GPL version 3
 *
 * @link https://github.com/webpa/webpa
 */

namespace WebPA\includes\classes;

use WebPA\includes\functions\Common;

include_once __DIR__ . '/../inc_global.php';

class Form {
  // Public Vars
  public $id = null;
  public $name = '';
  public $modules = null;
  public $type = null;

  // Private Vars
  private $_DAO = null;
  private $_questions = null;
  private $_xml_parser = null;

  /*
  * CONSTRUCTOR
  *
  * @param mixed $DAO
  */
  function __construct(&$DAO) {
    $this->_DAO =& $DAO;
  }// /->Form()

/*
* ================================================================================
* Public Methods
* ================================================================================
*/

/*
* --------------------------------------------------------------------------------
* Load/Save Functions
* --------------------------------------------------------------------------------
*/

  /**
  * Create a new form ID
  */
  function create() {
    // generate a new form_id
    while (true) {
      $new_id = Common::uuid_create();
      if ($this->_DAO->fetch_value("SELECT COUNT(form_id) FROM " . APP__DB_TABLE_PREFIX . "form WHERE form_id = '$new_id'") == 0) { break; }
    }
    $this->id = $new_id;
  }// ->create()

  /**
  * Delete this Group (and all its members)
  */
  function delete() {

    $_module_id = Common::fetch_SESSION('_module_id', null);

    $this->_DAO->execute("DELETE FROM " . APP__DB_TABLE_PREFIX . "form_module WHERE (form_id = '{$this->id}') AND (module_id = {$_module_id})");
    $this->_DAO->execute("DELETE FROM " . APP__DB_TABLE_PREFIX . "form WHERE form_id = '{$this->id}'");

    return true;

  }// /->delete()

  /**
  * Load the Form from the database
  *
  * @param string $id  id of Group to load
  * @return boolean did load succeed
  */
  function load($id) {
    $row = $this->_DAO->fetch_row("SELECT * FROM " . APP__DB_TABLE_PREFIX . "form f WHERE f.form_id = '$id'");
    return ($row) ? $this->load_from_row($row) : false;
  }// /->load()

  /**
  * Load the Form from an array row
  *
  *
  * @param array $row assoc array ( field => value, ... ) - corresponds to row in database
  * @return boolean  did load succeed
  */
  function load_from_row(&$row) {
    $this->id = $row['form_id'];
    $this->name = $row['form_name'];
    $this->type = (is_null($row['form_type'])) ? 'likert' : $row['form_type'];
    $this->_load_xml($row['form_xml']);
    return true;
  }// /->load_from_row()

  /**
  * Load the Form from xml
  *
  * @param string $xml xml fragment to load
   *
  * @return boolean did load succeed
  */
  function load_from_xml($xml) {
    $this->_load_xml($xml);

    return true;
  }

  /**
  * Save this Form
  * @return boolean did save succeed
  */
  function save() {
    if (!$this->id) {
      return false;
    } else {
      // Actually create and save the xml
      $form_xml = $this->_DAO->escape_str($this->get_xml());

      // Save the Form
      $this->_DAO->execute('INSERT INTO ' . APP__DB_TABLE_PREFIX . 'form (form_id, form_name, form_type, form_xml) ' .
         "VALUES ('{$this->id}', '" . $this->_DAO->escape_str($this->name) . "', '" . $this->_DAO->escape_str($this->type) . "', '') " .
         "ON DUPLICATE KEY UPDATE form_name = '" . $this->_DAO->escape_str($this->name) . "', form_type = '" . $this->_DAO->escape_str($this->type) . "'");
      $this->_DAO->execute('UPDATE ' . APP__DB_TABLE_PREFIX . "form SET form_xml = '{$form_xml}' WHERE form_id = '{$this->id}'");

      if ($this->modules !== null && count($this->modules) > 0) {
        $values = array();
        foreach($this->modules as $module_id) {
          $values[] = "('{$this->id}', {$module_id})";
        }
        $sql = 'INSERT INTO ' . APP__DB_TABLE_PREFIX . 'form_module (form_id, module_id) VALUES ' . implode(', ', $values);
        $this->_DAO->execute($sql);
      }

      return true;
    }
  }// /->save()

/*
* --------------------------------------------------------------------------------
* Other Methods
* --------------------------------------------------------------------------------
*/

  /**
  * Create a clone of this form
  * @return mixed returns the clone of the form
  */
  function & get_clone() {
    $clone_form = new self($this->_DAO);
    $clone_form->create();  // Changes the form's ID so it is officially a new form
    $temp_id = $clone_form->id;
    $clone_form->load_from_xml($this->get_xml()); // Creates an EXACT clone of the existing form
    $clone_form->id = $temp_id;
    return $clone_form;
  }// /->get_clone()

/*
* --------------------------------------------------------------------------------
* Question Manipulation Methods
* --------------------------------------------------------------------------------
*/

  /**
  * Add a question to this form
  * @param array $question_array
  */
  function add_question($question_array) {
    if ($this->get_question_count()>0) {
      $this->_questions[] = $question_array;
    } else {
      $this->_questions[0] = $question_array;
    }
  }// /->add_question()

  /**
  * Get an individual question's info
  * @param integer $index
  * @return array Questions at the point in the array
  */
  function get_question($index) {
    $index = (int) $index;
    if (array_key_exists($index, (array) $this->_questions)) {
      return $this->_questions[$index];
    }
  }// /->get_question()

  /**
  * Get a count of the number of questions
  * @return integer result of the count of the number of questions
  */
  function get_question_count() {
    $result = 0;
    if (is_array($this->_questions)) {
      $result = count($this->_questions);
    }
    return  $result;
  }// /->get_question_count()

  /**
  * Set an individual question's info
  * @param integer $index
  * @param array $question_array
  */
  function set_question($index, $question_array) {
    $index = (int) $index;
    $this->_questions[$index] = $question_array;
  }// /->set_question()

  /**
  * Remove a question from this form
  * @param integer $index
  */
  function remove_question($index) {
    $index = (int) $index;
    if (array_key_exists($index, (array) $this->_questions)) {
      unset($this->_questions[$index]);
      $this->_questions = array_values($this->_questions);
    }
  }// /->remove_question()

/*
* --------------------------------------------------------------------------------
* XML Methods
* --------------------------------------------------------------------------------
*/

  /**
  * Get the xml representation of this form
  * @return string xml fragment (no <?xml ?> starting tag)
  */
  function get_xml() {
    if (!is_object($this->_xml_parser)) { $this->_xml_parser = new XMLParser(); }

    // Create an array representation of the form's xml
    $xml_array['form']['formid']['_data'] = $this->id;
    $xml_array['form']['formname']['_data'] = $this->name;
    $xml_array['form']['formtype']['_data'] = $this->type;

    // Add any questions to the xml
    if ($this->get_question_count()>0) {
      foreach ($this->_questions as $i => $question) {
        $question_desc = (array_key_exists('desc', $question)) ? $question['desc']['_data'] : '' ;
        $new_question = array ('questionid' => 'Q'.($i+1) ,
                     'text'       => $question['text']['_data'] ,
                     'desc'       => $question_desc ,);

        $questions_to_save[] = $new_question;
      }
      $xml_array['form']['question'] = $this->_questions;
    }

    $this->_xml_parser->set_cdata_tags('desc');
    return $this->_xml_parser->generate_xml($xml_array);
  }// /->get_xml()

  /**
  * Load the xml data of the form (questions, etc)
  * @param mixed $xml_data
  */
  function _load_xml(&$xml_data) {
    if (!is_object($this->_xml_parser)) { $this->_xml_parser = new XMLParser(); }
    $xml_array = $this->_xml_parser->parse($xml_data);

    // Load form properties
    $this->id = $xml_array['form']['formid']['_data'];
    $this->name = $xml_array['form']['formname']['_data'];
    $this->type =  (array_key_exists('formtype', $xml_array['form'])) ? $xml_array['form']['formtype']['_data'] : 'likert';

    // Load the questions
    if ( (is_array($xml_array['form'])) && (array_key_exists('question',$xml_array['form'])) ) {
      $temp_questions =& $xml_array['form']['question'];
      if ($temp_questions) {
        // If there's only one question, make sure the array is restructured properly
        if ( (is_array($temp_questions)) && (array_key_exists(0, $temp_questions)) ) {
          $this->_questions =& $temp_questions;
        } else {
          $this->_questions[] =& $temp_questions;
        }
      }
    } else {
      $this->_questions = null;
    }
  }// /->_load_xml()

/*
* ================================================================================
* Private Methods
* ================================================================================
*/

}// /class: Form

?>
