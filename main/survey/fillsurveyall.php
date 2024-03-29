<?php
/* For licensing terms, see /dokeos_license.txt */

/**
*	@package dokeos.survey
* 	@author unknown, the initial survey that did not make it in 1.8 because of bad code
* 	@author Patrick Cool <patrick.cool@UGent.be>, Ghent University: cleanup, refactoring and rewriting large parts of the code
*	@author Julio Montoya Armas <gugli100@gmail.com>, Dokeos: Personality Test modification and rewriting large parts of the code as well
* 	@version $Id: survey_list.php 10680 2007-01-11 21:26:23Z pcool $
*
* 	@todo use quickforms for the forms
* 	@todo check if the user already filled the survey and if this is the case then the answers have to be updated and not stored again.
* 		  alterantively we could not allow people from filling the survey twice.
* 	@todo performance could be improved if not the survey_id was stored with the invitation but the survey_code
*/

// name of the language file that needs to be included
$language_file = 'survey';
// unsetting the course id (because it is in the URL)
if (!isset($_GET['cidReq']))
{
	$cidReset = true;
}
else
{
	$_cid = $_GET['cidReq'];
}

// including the global dokeos file
require ('../inc/global.inc.php');

// including additional libraries
//require_once (api_get_path(LIBRARY_PATH)."survey.lib.php");
require_once('survey.lib.php');
require_once (api_get_path(LIBRARY_PATH).'course.lib.php');

$origin = '';
$origin_string='';
if (isset($_GET['origin'])) {
	$origin =  Security::remove_XSS($_GET['origin']);
	$origin_string = '&origin='.$origin;
}
if ($origin == 'learnpath') {
$htmlHeadXtra[] = '<script type="text/javascript" src="' . api_get_path(WEB_LIBRARY_PATH) . 'javascript/jquery-1.4.2.min.js" language="javascript"></script>';
}
$htmlHeadXtra[] = '<script type="text/javascript" src="' . api_get_path(WEB_LIBRARY_PATH) . 'javascript/customInput.jquery.js" language="javascript"></script>';

// breadcrumbs
if (!empty($_user))
{
	$interbreadcrumb[] = array ("url" => 'survey_list.php?cidReq='.$_GET['course'],'name' => get_lang('SurveyList'));
}

// Header
if ($origin == 'learnpath') {
	include_once api_get_path(INCLUDE_PATH).'reduced_header.inc.php';
} else {
  Display::display_tool_header();
}
?>
<script type="text/javascript"> 
	// Run the script on DOM ready:
	$(function(){		
		$('input').customInput();
	});
	</script>	
<?php

// start the content div
echo '<div id="content">';
// getting all the course information
$_course = CourseManager::get_course_information($_GET['course']);

// Database table definitions
$table_survey 					= Database :: get_course_table(TABLE_SURVEY, $_course['db_name']);
$table_survey_answer			= Database :: get_course_table(TABLE_SURVEY_ANSWER, $_course['db_name']);
$table_survey_question 			= Database :: get_course_table(TABLE_SURVEY_QUESTION, $_course['db_name']);
$table_survey_question_option 	= Database :: get_course_table(TABLE_SURVEY_QUESTION_OPTION, $_course['db_name']);
$table_course 					= Database :: get_main_table(TABLE_MAIN_COURSE);
$table_user 					= Database :: get_main_table(TABLE_MAIN_USER);
$table_survey_invitation 		= Database :: get_course_table(TABLE_SURVEY_INVITATION, $_course['db_name']);

// first we check if the needed parameters are present
if ((!isset ($_GET['course']) || !isset ($_GET['invitationcode']))&& !isset($_GET['user_id']))
{
	Display :: display_error_message(get_lang('SurveyParametersMissingUseCopyPaste'), false);
	Display :: display_footer();
	exit;
}
$invitationcode = $_GET['invitationcode'];
// start auto-invitation feature FS#3403 (all-users-can-do-the-survey-URL handling)
if ($invitationcode == "auto" && isset($_GET['scode'])){
    // not intended for anonymous users
    if (!(isset ($_user['user_id']) && $_user['user_id']) || api_is_anonymous($_user['user_id'],true)) {
		api_not_allowed();
    }
    $userid = $_user['user_id'];
    $scode = Database::escape_string($_GET['scode']); //survey_code of the survey
    $autoInvitationcode = "auto-$userid-".$scode; //new invitation code from userid
    // the survey code must exist in this course, or the URL is invalid
    $sql = "SELECT * FROM $table_survey WHERE code='" . $scode . "'";
    $result = Database::query($sql, __FILE__, __LINE__);
    if (Database :: num_rows($result) > 0){  // ok
        // check availability
        $row = Database :: fetch_array($result, 'ASSOC'); //
        $tempdata  = survey_manager :: get_survey($row['survey_id']);
        check_time_availability($tempdata); //exit if survey not available anymore
        // check for double invitation records (insert should be done once)
        $sql = "SELECT user from $table_survey_invitation where invitation_code = '".Database::escape_string($autoInvitationcode)."'";
        $result = Database::query($sql, __FILE__, __LINE__);
        if (Database :: num_rows($result) == 0){ // ok
            $sql = "insert into $table_survey_invitation (survey_code,user, invitation_code, invitation_date) ";
            $sql .= " values (\"$scode\", \"$userid\", \"$autoInvitationcode\", now())";
            Database::query($sql, __FILE__, __LINE__);
        }
        // from here we use the new invitationcode auto-userid-surveycode string
        $_GET['invitationcode'] = $autoInvitationcode;
        $invitationcode = $autoInvitationcode;
    }
}
// end auto-invitation feature

// now we check if the invitationcode is valid
$sql = "SELECT * FROM $table_survey_invitation WHERE invitation_code = '" . Database :: escape_string($invitationcode) . "'";
$result = Database::query($sql, __FILE__, false); // false=suppress errors
if (Database::num_rows($result) < 1)
{
	Display :: display_error_message(get_lang('WrongInvitationCode'), false);
	Display :: display_footer();
	exit;
}
$survey_invitation = Database::fetch_array($result,'ASSOC');

// now we check if the user already filled the survey
if ($survey_invitation['answered'] == 1 && !isset($_GET['user_id']))
{
	Display :: display_error_message(get_lang('YouAlreadyFilledThisSurvey'), false);
	Display :: display_footer();
	exit();
}

// checking if there is another survey with this code.
// If this is the case there will be a language choice
$sql = "SELECT * FROM $table_survey WHERE code='".Database::escape_string($survey_invitation['survey_code'])."'";

$result = Database::query($sql, __FILE__, __LINE__);

if (Database::num_rows($result) > 1)
{
	if ($_POST['language'])
	{
		$survey_invitation['survey_id'] = $_POST['language'];
	}
	else
	{
		echo '<form id="language" name="language" method="POST" action="'.api_get_self().'?course='.Security::remove_XSS($_GET['course']).'&invitationcode='.Security::remove_XSS($_GET['invitationcode']).'&cidReq='.Security::remove_XSS($_GET['cidReq']).$origin_string.'">';
		echo '  <select name="language">';
		while ($row=Database::fetch_array($result,'ASSOC'))
		{
			echo '<option value="'.$row['survey_id'].'">'.$row['lang'].'</option>';
		}
		echo '</select>';
		//echo '  <input type="submit" name="Submit" value="' . get_lang('Ok') . '" class="next" />';
		echo '<button type="submit" name="Submit" class="next">'.get_lang('Ok').'</button>';
		echo '</form>';
  // Close content
  echo '</div>';
  
  /*echo '<div class="actions">';
  echo '&nbsp;';
  echo '</div>';*/

		display::display_footer();
		exit();
	}
}
else
{
	$row=Database::fetch_array($result,'ASSOC');
	$survey_invitation['survey_id'] = $row['survey_id'];
}

// getting the survey information
$survey_data = survey_manager::get_survey($survey_invitation['survey_id']);
//echo '<pre>';print_r($survey_data);echo '</pre>';exit;

$survey_data['survey_id'] = $survey_invitation['survey_id'];
//print_r($survey_data);
// storing the answers

if (count($_POST)>0)
{	
    if ($survey_data['survey_type']==='0')
	{
		// getting all the types of the question (because of the special treatment of the score question type
		$sql = "SELECT * FROM $table_survey_question WHERE survey_id = '".Database::escape_string($survey_invitation['survey_id'])."'";
		$result = Database::query($sql, __FILE__, __LINE__);

		while ($row = Database::fetch_array($result,'ASSOC'))
		{
			$types[$row['question_id']] = $row['type'];
		}

		// looping through all the post values
		foreach ($_POST as $key=>$value)
		{				
			// if the post value key contains the string 'question' then it is an answer on a question
			if (strstr($key, 'question'))
			{
				// finding the question id by removing 'question'
				$survey_question_id = str_replace('question', '',$key);

				// if the post value is an array then we have a multiple response question or a scoring question type
				// remark: when it is a multiple response then the value of the array is the option_id
				// 		   when it is a scoring question then the key of the array is the option_id and the value is the value
				if (is_array($value))
				{					
					SurveyUtil::remove_answer($survey_invitation['user'], $survey_invitation['survey_id'], $survey_question_id);
					foreach ($value as $answer_key => $answer_value)
					{
						if ($types[$survey_question_id] == 'score')
						{
							$option_id = $answer_key;
							$option_value = $answer_value;
						}
						else
						{							
							$option_id = $answer_value;
							$option_value = '';
						}
						
						SurveyUtil::store_answer($survey_invitation['user'], $survey_invitation['survey_id'], $survey_question_id, $option_id, $option_value, $survey_data);
					}
				}
				// all the other question types (open question, multiple choice, percentage, ...)
				else
				{
					if ($types[$survey_question_id] == 'percentage')
					{
						$sql = "SELECT * FROM $table_survey_question_option WHERE question_option_id='".Database::escape_string($value)."'";
						$result = Database::query($sql, __FILE__, __LINE__);
						$row = Database::fetch_array($result,'ASSOC');
						$option_value = $row['option_text'];
					} else {
						$option_value = 0;
						if ($types[$survey_question_id] == 'open') {
							$option_value = $value;
							//$value = 0;
						}
					}				
					
					$survey_question_answer = $value;
					SurveyUtil::remove_answer($survey_invitation['user'], $survey_invitation['survey_id'], $survey_question_id);
					SurveyUtil::store_answer($survey_invitation['user'], $survey_invitation['survey_id'], $survey_question_id, $value, $option_value, $survey_data);
					//SurveyUtil::store_answer($user,$survey_id,$question_id, $option_id, $option_value, $survey_data);
				}
			}			
		}
	}
	elseif ($survey_data['survey_type']==='1')	//conditional/personality-test type surveys
    {
		// getting all the types of the question (because of the special treatment of the score question type
		$shuffle='';
		if ($survey_data['shuffle']=='1')
		{
			$shuffle= ' ORDER BY RAND() '	;
		}
		$sql = "SELECT * FROM $table_survey_question
				WHERE survey_id = '".Database::escape_string($survey_invitation['survey_id'])."'
				AND survey_group_pri='0' $shuffle
				";
		$result = Database::query($sql, __FILE__, __LINE__);
        // there is only one question type for conditional surveys
		while ($row = Database::fetch_array($result,'ASSOC'))
		{
			$types[$row['question_id']] = $row['type'];
		}

		// looping through all the post values
		foreach ($_POST as $key=>$value)
		{
			// if the post value key contains the string 'question' then it is an answer to a question
			if (strstr($key,'question'))
			{
				// finding the question id by removing 'question'
				$survey_question_id = str_replace('question', '',$key);
				// we select the correct answer and the puntuacion
				$sql = "SELECT value FROM $table_survey_question_option " .
                        " WHERE question_option_id='".Database::escape_string($value)."'";
				$result = Database::query($sql, __FILE__, __LINE__);
				$row = Database::fetch_array($result,'ASSOC');
				$option_value = $row['value'];
				//$option_value = 0;
				$survey_question_answer = $value;
                //we save the answer after making sure that a possible previous attempt is deleted
				SurveyUtil::remove_answer($survey_invitation['user'], $survey_invitation['survey_id'], $survey_question_id);
				SurveyUtil::store_answer($survey_invitation['user'], $survey_invitation['survey_id'], $survey_question_id, $value, $option_value, $survey_data);
				//SurveyUtil::store_answer($user,$survey_id,$question_id, $option_id, $option_value, $survey_data);
			}
		}
	}
    else //in case it's another type than 0 or 1
    {
    	die(get_lang('ErrorSurveyTypeUnknown'));
    }
}

// displaying the survey title and subtitle (appears on every page)
//echo '<div id="content">';
echo '<div class="actions" style="height:auto;min-height:300px;">';
echo '<div id="survey_title">'.$survey_data['survey_title'].'</div>';
echo '<div id="survey_subtitle">'.$survey_data['survey_subtitle'].'</div>';

// checking time availability
check_time_availability($survey_data);

// displaying the survey introduction
if (!isset($_GET['show']))
{
	// the first thing we do is delete the session
	unset($_SESSION['paged_questions']);
	unset($_SESSION['page_questions_sec']);
	$paged_questions_sec=array();

	echo '<div id="survey_content" class="survey_content">'.$survey_data['survey_introduction'].'</div>';
	$limit = 0;
}

$user_id = api_get_user_id();

if ($user_id == 0)
{
	$user_id = $survey_invitation['user'];
}
$user_data = UserManager :: get_user_info_by_id($user_id);

if ($survey_data['form_fields']!='' && $survey_data['anonymous'] == 0 && is_array($user_data))
{
	//echo "<pre>"; print_r($survey_invitation);
	$form_fields = explode('@', $survey_data['form_fields']);
	$list = array ();
	foreach ($form_fields as $field) {
		$field_value = explode(':', $field);
		if ($field_value[1] == 1) {
			if ($field_value[0] != '') {
				$val = api_substr($field_value[0], 8, api_strlen($field_value[0]));
				$list[$val] = 1;
			}
		}
	}

	// we use the same form as in auth/profile.php
	require_once (api_get_path(LIBRARY_PATH) . 'formvalidator/FormValidator.class.php');
	$form = new FormValidator('profile', 'post', api_get_self() . "?" . str_replace('&show_form=1', '&show_form=1', $_SERVER['QUERY_STRING']), null, array (
		'style' => 'width: 75%; float: ' . ($text_dir == 'rtl' ? 'right;' : 'left;')
	));

	if (api_is_western_name_order()) {
		if ($list['firstname'] == 1 ) {
			//FIRST NAME
			$form->addElement('text', 'firstname', get_lang('FirstName'), array ('size' => 40));
			if (api_get_setting('profile', 'name') !== 'true') {
				$form->freeze(array ('firstname'));
			}
			$form->applyFilter(array ('firstname'), 'stripslashes');
			$form->applyFilter(array ('firstname'), 'trim');
			$form->addRule('firstname', get_lang('ThisFieldIsRequired'), 'required');
		}
		if ($list['lastname'] == 1) {
			//	LAST NAME
			$form->addElement('text', 'lastname', get_lang('LastName'), array ('size' => 40));
			if (api_get_setting('profile', 'name') !== 'true') {
				$form->freeze(array ('lastname'));
			}
			$form->applyFilter(array ('lastname'), 'stripslashes');
			$form->applyFilter(array ('lastname'), 'trim');
			$form->addRule('lastname', get_lang('ThisFieldIsRequired'), 'required');
		}
	} else {
		if ($list['lastname'] == 1) {
			//	LAST NAME
			$form->addElement('text', 'lastname', get_lang('LastName'), array ('size' => 40));
			if (api_get_setting('profile', 'name') !== 'true') {
				$form->freeze(array ('lastname'));
			}
			$form->applyFilter(array ('lastname'), 'stripslashes');
			$form->applyFilter(array ('lastname'), 'trim');
			$form->addRule('lastname', get_lang('ThisFieldIsRequired'), 'required');
		}
		if ($list['firstname'] == 1 ) {
			//FIRST NAME
			$form->addElement('text', 'firstname', get_lang('FirstName'), array ('size' => 40));
			if (api_get_setting('profile', 'name') !== 'true') {
				$form->freeze(array ('firstname'));
			}
			$form->applyFilter(array ('firstname'), 'stripslashes');
			$form->applyFilter(array ('firstname'), 'trim');
			$form->addRule('firstname', get_lang('ThisFieldIsRequired'), 'required');
		}
	}

	if ($list['official_code'] == 1) {
		//	OFFICIAL CODE
		if (CONFVAL_ASK_FOR_OFFICIAL_CODE) {
			$form->addElement('text', 'official_code', get_lang('OfficialCode'), array (
				'size' => 40
			));
			if (api_get_setting('profile', 'officialcode') !== 'true')
				$form->freeze('official_code');
			$form->applyFilter('official_code', 'stripslashes');
			$form->applyFilter('official_code', 'trim');
			if (api_get_setting('registration', 'officialcode') == 'true' && api_get_setting('profile', 'officialcode') == 'true')
				$form->addRule('official_code', get_lang('ThisFieldIsRequired'), 'required');
		}
	}
	if ($list['email'] == 1) {
		//	EMAIL
		$form->addElement('text', 'email', get_lang('Email'), array (
			'size' => 40
		));
		if (api_get_setting('profile', 'email') !== 'true')
			$form->freeze('email');
		$form->applyFilter('email', 'stripslashes');
		$form->applyFilter('email', 'trim');
		if (api_get_setting('registration', 'email') == 'true')
			$form->addRule('email', get_lang('ThisFieldIsRequired'), 'required');
		$form->addRule('email', get_lang('EmailWrong'), 'email');
	}

	if ($list['phone'] == 1) {
		//	PHONE
		$form->addElement('text', 'phone', get_lang('phone'), array (
			'size' => 20
		));
		if (api_get_setting('profile', 'phone') !== 'true')
			$form->freeze('phone');
		$form->applyFilter('phone', 'stripslashes');
		$form->applyFilter('phone', 'trim');
		if (api_get_setting('profile', 'phone') == 'true')
			$form->addRule('phone', get_lang('ThisFieldIsRequired'), 'required');
	}

	if ($list['language'] == 1) {
		//	LANGUAGE
		$form->addElement('select_language', 'language', get_lang('Language'));
		if (api_get_setting('profile', 'language') !== 'true')
			$form->freeze('language');
		if (api_get_setting('profile', 'language') == 'true')
			$form->addRule('language', get_lang('ThisFieldIsRequired'), 'required');
	}

	// EXTRA FIELDS
	$extra = UserManager :: get_extra_fields(0, 50, 5, 'ASC');
	$extra_data = UserManager :: get_extra_user_data($user_id, true);
	foreach ($extra as $id => $field_details) {
		if ($field_details[6] == 0) {
			continue;
		}

		if ($list['extra_' . $field_details[1]] == 1) {
			switch ($field_details[2]) {
				case USER_FIELD_TYPE_TEXT :
					$form->addElement('text', 'extra_' . $field_details[1], $field_details[3], array (
						'size' => 40
					));
					$form->applyFilter('extra_' . $field_details[1], 'stripslashes');
					$form->applyFilter('extra_' . $field_details[1], 'trim');

					if ($field_details[7] == 0)
						$form->freeze('extra_' . $field_details[1]);
					break;
				case USER_FIELD_TYPE_TEXTAREA :
					$form->add_html_editor('extra_' . $field_details[1], $field_details[3], false, false, array('ToolbarSet' => 'Profile', 'Width' => '100%', 'Height' => '130'));
					$form->applyFilter('extra_' . $field_details[1], 'stripslashes');
					$form->applyFilter('extra_' . $field_details[1], 'trim');

					if ($field_details[7] == 0)
						$form->freeze('extra_' . $field_details[1]);
					break;
				case USER_FIELD_TYPE_RADIO :
					$group = array ();
					foreach ($field_details[9] as $option_id => $option_details) {
						$options[$option_details[1]] = $option_details[2];
						$group[] = & HTML_QuickForm :: createElement('radio', 'extra_' . $field_details[1], $option_details[1], $option_details[2] . '<br />', $option_details[1]);
					}
					$form->addGroup($group, 'extra_' . $field_details[1], $field_details[3], '');

					if ($field_details[7] == 0)
						$form->freeze('extra_' . $field_details[1]);
					break;
				case USER_FIELD_TYPE_SELECT :
					$options = array ();
					foreach ($field_details[9] as $option_id => $option_details) {
						$options[$option_details[1]] = $option_details[2];
					}
					$form->addElement('select', 'extra_' . $field_details[1], $field_details[3], $options, '');
					if ($field_details[7] == 0)
						$form->freeze('extra_' . $field_details[1]);
					break;
				case USER_FIELD_TYPE_SELECT_MULTIPLE :
					$options = array ();
					foreach ($field_details[9] as $option_id => $option_details) {
						$options[$option_details[1]] = $option_details[2];
					}
					$form->addElement('select', 'extra_' . $field_details[1], $field_details[3], $options, array (
						'multiple' => 'multiple'
					));
					if ($field_details[7] == 0)
						$form->freeze('extra_' . $field_details[1]);
					break;
				case USER_FIELD_TYPE_DATE :
					$form->addElement('datepickerdate', 'extra_' . $field_details[1], $field_details[3],array('form_name'=>'profile'));
					$form->_elements[$form->_elementIndex['extra_' . $field_details[1]]]->setLocalOption('minYear', 1900);
					$defaults['extra_'.$field_details[1]] = date('Y-m-d 12:00:00');
					$form -> setDefaults($defaults);
					if ($field_details[7] == 0)
						$form->freeze('extra_' . $field_details[1]);
					$form->applyFilter('theme', 'trim');
					break;
				case USER_FIELD_TYPE_DATETIME :
					$form->addElement('datepicker', 'extra_' . $field_details[1], $field_details[3],array('form_name'=>'profile'));
					$form->_elements[$form->_elementIndex['extra_'.$field_details[1]]]->setLocalOption('minYear',1900);
					$defaults['extra_'.$field_details[1]] = date('Y-m-d 12:00:00');
					$form -> setDefaults($defaults);
					if ($field_details[7] == 0)
						$form->freeze('extra_' . $field_details[1]);
					$form->applyFilter('theme', 'trim');
					break;
				case USER_FIELD_TYPE_DOUBLE_SELECT :
					foreach ($field_details[9] as $key => $element) {
						if ($element[2][0] == '*') {
							$values['*'][$element[0]] = str_replace('*', '', $element[2]);
						} else {
							$values[0][$element[0]] = $element[2];
						}
					}
					$group = '';
					$group[] = & HTML_QuickForm :: createElement('select', 'extra_' . $field_details[1], '', $values[0], '');
					$group[] = & HTML_QuickForm :: createElement('select', 'extra_' . $field_details[1] . '*', '', $values['*'], '');
					$form->addGroup($group, 'extra_' . $field_details[1], $field_details[3], '&nbsp;');
					if ($field_details[7] == 0)
						$form->freeze('extra_' . $field_details[1]);

					// recoding the selected values for double : if the user has selected certain values, we have to assign them to the correct select form
					if (key_exists('extra_' . $field_details[1], $extra_data)) {
						// exploding all the selected values (of both select forms)
						$selected_values = explode(';', $extra_data['extra_' . $field_details[1]]);
						$extra_data['extra_' . $field_details[1]] = array ();

						// looping through the selected values and assigning the selected values to either the first or second select form
						foreach ($selected_values as $key => $selected_value) {
							if (key_exists($selected_value, $values[0])) {
								$extra_data['extra_' . $field_details[1]]['extra_' . $field_details[1]] = $selected_value;
							} else {
								$extra_data['extra_' . $field_details[1]]['extra_' . $field_details[1] . '*'] = $selected_value;
							}
						}
					}
					break;
				case USER_FIELD_TYPE_DIVIDER :
					$form->addElement('static', $field_details[1], '<br /><strong>' . $field_details[3] . '</strong>');
					break;
			}
			$form->addRule('extra_' . $field_details[1], get_lang('ThisFieldIsRequired'), 'required');
		}
	}
	$form->addElement('style_submit_button', '', get_lang('Next'),array('class'=>'next'));
	$user_data = array_merge($user_data, $extra_data);
	$form->setDefaults($user_data);
}

if ($survey_data['form_fields'] && $survey_data['anonymous'] == 0 && is_array($user_data) && !isset ($_GET['show'])) {
	if ($form->validate()) {
		$user_data = $form->exportValues();
		if (is_array($user_data)) {
			if (count($user_data)>0) {
				$extras = array ();
				// build SQL query
				$sql = "UPDATE $table_user SET";
				foreach ($user_data as $key => $value) {
					if (substr($key, 0, 6) == 'extra_') { //an extra field
						$extras[substr($key, 6)] = $value;
					} else {
						$sql .= " $key = '" . Database :: escape_string($value) . "',";
					}
				}
				// remove trailing , from the query we have so far
				$sql = rtrim($sql, ',');
				$sql .= " WHERE user_id  = '" . $user_id . "'";
				Database::query($sql, __FILE__, __LINE__);
				//update the extra fields
				if (is_array($extras)) {
					foreach ($extras as $key => $value) {
						$myres = UserManager :: update_extra_field_value($user_id, $key, $value);
					}
				}
				echo '<div id="survey_content" class="survey_content">' . get_lang('InformationUpdated') . ' ' . get_lang('PleaseFillSurvey') . '</div>';
			}
		}
		//$_GET['show_form']=0;
		//$show_form=0;
		$_GET['show']=0;
		$show=0;
		//we unset the sessions
		unset($_SESSION['paged_questions']);
		unset($_SESSION['page_questions_sec']);
		$paged_questions_sec=array();

	}
	//elseif ($_GET['show_form']==1) //	// displaying the field
	else {
		echo '<div id="survey_content" class="survey_content">' . get_lang('UpdateInformation') . '</div>';
		//we unset the sessions
		unset($_SESSION['paged_questions']);
		unset($_SESSION['page_questions_sec']);
		$paged_questions_sec=array();
		$form->display();
	}
}

// displaying the survey thanks message
if (isset($_POST['finish_survey']))
{
	echo '<div id="survey_content" class="survey_content"><strong>'.get_lang('SurveyFinished').'</strong> <br />'.$survey_data['survey_thanks'].'<br/><br/><a href="../../courses/'.api_get_course_id().'/index.php">'.get_lang('CourseHome').'</div>';
	survey_manager::update_survey_answered($survey_data['survey_id'], $survey_invitation['user'], $survey_invitation['survey_code']);
	unset($_SESSION['paged_questions']);
	unset($_SESSION['page_questions_sec']);
 // Close content
 echo '</div>';
 
 echo '</div>';

if ($origin != 'learnpath') {
        echo '<div class="actions"></div>';
	Display :: display_footer();
}
exit();
}

// sets the random questions
$shuffle='';
if ($survey_data['shuffle']==1)
{
	$shuffle= ' BY RAND() ';
}

$sql = "SELECT * FROM $table_survey_question
			WHERE survey_id = '".Database::escape_string($survey_invitation['survey_id'])."'
			AND	type <> '".Database::escape_string('pagebreak')."' ORDER BY sort ASC";
		$result = Database::query($sql, __FILE__, __LINE__);
		$numrows = Database::num_rows($result);

// Displaying the form with the questions
$g_c = (isset($_GET['course'])?Security::remove_XSS($_GET['course']):'');
$g_ic = (isset($_GET['invitationcode'])?Security::remove_XSS($_GET['invitationcode']):'');
$g_cr = (isset($_GET['cidReq'])?Security::remove_XSS($_GET['cidReq']):'');
$p_l = (isset($_POST['language'])?Security::remove_XSS($_POST['language']):'');

$add_parameters=isset($_GET['user_id']) ? 'user_id='.$_GET['user_id'].'&amp;': '';

if ( isset($_GET['show']) || isset($_POST['personality']))
{
	// Getting all the questions for this page and add them to a multidimensional array where the first index is the page.
	// as long as there is no pagebreak fount we keep adding questions to the page
	$questions_displayed = array();
	$counter = 0;
    $paged_questions = array();

	// if non-conditional survey
	if ($survey_data['survey_type']==='0')
	{		
		$counter = 0;
		$paged_questions = array();
		$show = $_GET['show'];
		while ($row = Database::fetch_array($result))
		{		
			$paged_questions[] = $row['question_id'];
		}
		
		$sql = "SELECT 	survey_question.question_id, survey_question.survey_id, survey_question.survey_question, survey_question.display, survey_question.sort, survey_question.type, survey_question.max_value,
							survey_question_option.question_option_id, survey_question_option.option_text, survey_question_option.sort as option_sort
					FROM $table_survey_question survey_question
					LEFT JOIN $table_survey_question_option survey_question_option
					ON survey_question.question_id = survey_question_option.question_id
					WHERE survey_question.survey_id = '".Database::escape_string($survey_invitation['survey_id'])."'
					
					ORDER BY survey_question.sort, survey_question_option.sort ASC";
		$result = Database::query($sql, __FILE__, __LINE__);		
		$questions = array();
		while ($row = Database::fetch_array($result))
		{
			// if the type is not a pagebreak we store it in the $questions array
			if($row['type'] <> 'pagebreak')
			{
				$questions[$row['sort']]['question_id'] = $row['question_id'];
				$questions[$row['sort']]['survey_id'] = $row['survey_id'];
				$questions[$row['sort']]['survey_question'] = $row['survey_question'];
				$questions[$row['sort']]['display'] = $row['display'];
				$questions[$row['sort']]['type'] = $row['type'];
			//	$questions[$row['sort']]['options'][intval($row['option_sort'])] = $row['option_text'];
				$questions[$row['sort']]['options'][$row['question_option_id']] = $row['option_text'];
				$questions[$row['sort']]['maximum_score'] = $row['max_value'];
			}
			// if the type is a pagebreak we are finished loading the questions for this page
			else
			{
				break;
			}			
		}
		// Displaying the form with the questions
	
	}
	elseif ($survey_data['survey_type']==='1')
	{
		$my_survey_id=Database::escape_string($survey_invitation['survey_id']);
		$current_user = Database::escape_string($survey_invitation['user']);

		if (isset($_POST['personality']))
		{
			// Compute the results to get the 3 groups nearest to the user's personality
			if ($shuffle=='')
				$order = 'BY sort ASC ';
			else
				$order = $shuffle;

			$answer_list=array();

			// get current user results
			$results=array();
			$sql = "SELECT survey_group_pri, user, SUM(value) as value
					FROM $table_survey_answer as survey_answer INNER JOIN $table_survey_question as survey_question
					ON  (survey_question.question_id = survey_answer.question_id)
					WHERE survey_answer.survey_id='".$my_survey_id."' AND
					survey_answer.user='".$current_user."'
					GROUP BY survey_group_pri
					ORDER BY survey_group_pri
					";

			$result = Database::query($sql, __FILE__, __LINE__);
			while ($row = Database :: fetch_array($result)) {
				$answer_list['value']=$row['value'];
				$answer_list['group']=$row['survey_group_pri'];
				$results[]=$answer_list;
			}

			//echo "<br>";print_r($results);	echo "<br>";

			// get the total score for each group of questions
			$totals=array();
			$sql = "SELECT SUM(temp.value) as value, temp.survey_group_pri FROM
					(SELECT MAX(value) as value,  survey_group_pri, survey_question.question_id
					FROM $table_survey_question as survey_question
					INNER JOIN $table_survey_question_option as survey_question_option
					ON (survey_question.question_id = survey_question_option.question_id)
					WHERE survey_question.survey_id='".$my_survey_id."'  AND  survey_group_sec1='0' AND survey_group_sec2='0'
					GROUP BY survey_group_pri, survey_question.question_id) as temp
					GROUP BY temp.survey_group_pri
					ORDER BY temp.survey_group_pri";

			$result = Database::query($sql, __FILE__, __LINE__);
			while ($row = Database::fetch_array($result))
			{
				$list['value']=$row['value'];
				$list['group']=$row['survey_group_pri'];
				$totals[]=$list;
			}
			//echo '<pre>'; print_r($totals);

			$final_results=array();

            //get a percentage score for each group
			for ($i = 0; $i < count($totals); $i++) {
				for ($j = 0; $j < count($results); $j++) {
					if ($totals[$i]['group'] == $results[$j]['group']) {
						$group=$totals[$i]['group'];
						$porcen=($results[$j]['value'] / $totals[$i]['value'] );
						$final_results[$group]=$porcen;
					}
				}
			}

			// sort the results by score (getting a list of group IDs by score into $groups)
			arsort($final_results);
			$groups=array_keys($final_results);
			/*
            echo '<pre>';
			echo 'Group id =>  %';
			echo "<br>";
			print_r($final_results);
            echo '</pre>';
		    */
			$result=array();
			$count_result=0;
			foreach ($final_results as $key => $sub_result) {
				$result[] = array (
					'group' => $key,
					'value' => $sub_result
				);
				$count_result++;
			}


			/*
			//i.e 70% - 70% -70% 70%  $equal_count =3
			while(1)
			{
				if ($result[$i]['value']  == $result[$i+1]['value'])
				{
					$equal_count++;
				}
				else
				{
					break;
				}
				$i++;
			}
			echo 'eq'. $equal_count;
			echo "<br>";
			if 	($equal_count==0)
			{
				//i.e 70% 70% -60% 60%  $equal_count = 1 only we get the first 2 options
				if ( ($result[0]['value']  == $result[1]['value'])  &&  ($result[2]['value']==$result[3]['value']) )
				{
					$group_cant=1;
				}
				else
				{	// by default we chose the highest 3
					$group_cant=2;
				}
			}
			elseif ($equal_count==2)
			{
				$group_cant=2;
			}
			else
			{
				$group_cant=-1;
			}
			*/

			//i.e 70% - 70% -70% 70%  $equal_count =3

			$i = 0;
			$group_cant = 0;
			$equal_count = 0;
			// this is the case if the user does not select any question
			if ($count_result>0)
			{
				// Count the number of scores equal to the first
				while (1)
				{
					if ($result[$i]['value'] == $result[$i +1]['value'])
					{
						$equal_count++;
					}
					else
					{
						break;
					}
					$i++;
				}
			}
			else
			{
				//we force the exit of the survey undeterminated
				$equal_count=10;
			}
			//echo '<pre>';
			//print_r($result);

            // if we have only 3 or less equal scores (i.e. 0,1 or 2 equalities), then we can use the three first groups
			if ($equal_count<4)
			{
				//if there is one or less score equalities
				if ($equal_count === 0 || $equal_count === 1)
					{
					//i.e 70% - 70% -60% - 60%  $equal_count = 1 we only get the first 2 options
					if (($result[0]['value'] == $result[1]['value']) && ($result[2]['value'] == $result[3]['value']))
					{
						$group_cant = 1;
					}
					//i.e 70% - 70% -0% - 0% 	-	$equal_count = 0 we only get the first 2 options
					/*elseif (($result[0]['value'] == $result[1]['value']) && ($result[1]['value'] != $result[2]['value']) ) {
						$group_cant=0;
					}*/
					/*
					//i.e 70% - 70% -60% - 60%  $equal_count = 0 we only get the first 2 options
					elseif ( ($result[0]['value']  == $result[1]['value'])  &&  ($result[2]['value'] == $result[3]['value']) )
					{
						$group_cant=0;
					}*/
					//i.e. 80% - 70% - 70% - 70%
					elseif (($result[0]['value'] != $result[1]['value']) && ($result[1]['value'] == $result[2]['value']) && ($result[2]['value'] == $result[3]['value'])) {
						$group_cant = 0;
					}
					else
					{
					//i.e. 80% - 70% - 70% - 50
					//i.e. 80% - 80% - 70% - 50

						// by default we choose the highest 3
						$group_cant=2;
					}
				}
				else
				{
					//if there are two score equalities
					$group_cant=$equal_count;
				}

				//@todo
				// conditional_status
				// 0 no determinado
				// 1 determinado
				// 2 un solo valor
				// 3 valores iguales

				if ($group_cant>0)
				{
					//echo '$equal_count'.$group_cant;
					// we only get highest 3
					$secondary ='';
					$combi='';

					for ($i = 0; $i <= $group_cant; $i++) {
						$group1=$groups[$i];
						$group2=$groups[$i+1];
						// here we made all the posibilities with the 3 groups
						if ($group_cant == 2 && $i == $group_cant) {
							$group2=$groups[0];
							$secondary .= " OR ( survey_group_sec1 = '$group1' AND  survey_group_sec2 = '$group2') ";
							$secondary .= " OR ( survey_group_sec1 = '$group2' AND survey_group_sec2 = '$group1' ) ";
							$combi.= $group1.' - '.$group2." or ".$group2.' - '.$group1."<br>";
						} else {
							if ($i != 0) {
								$secondary .= " OR ( survey_group_sec1 = '$group1' AND  survey_group_sec2 = '$group2') ";
								$secondary .= " OR ( survey_group_sec1 = '$group2' AND survey_group_sec2 = '$group1' ) ";
								$combi.= $group1.' - '.$group2." or ".$group2.' - '.$group1."<br>";
							} else {
								$secondary .= " ( survey_group_sec1 = '$group1' AND  survey_group_sec2 = '$group2') ";
								$secondary .= " OR ( survey_group_sec1 = '$group2' AND survey_group_sec2 = '$group1' ) ";
								$combi.= $group1.' - '.$group2." or ".$group2.' - '.$group1."<br>";
							}
						}
					}
					/*
                    echo '<pre>';
                    echo "Pair of Groups <br /><br />";
					echo $combi;
					echo '</pre>';
					*/
					// create the new select with the questions from the secondary phase
					if (empty($_SESSION['page_questions_sec']) && !is_array($_SESSION['page_questions_sec']) && count($_SESSION['page_questions_sec']==0))
					{

    					$sql = "SELECT * FROM $table_survey_question
    								 WHERE survey_id = '".$my_survey_id."'
    							  	 AND ($secondary )
    								 ORDER BY sort ASC";
    					$result = Database::query($sql, __FILE__, __LINE__);
    					$counter=0;
    					while ($row = Database::fetch_array($result,'ASSOC'))
    					{
    						if ($survey_data['one_question_per_page']==1)
    						{
    							$paged_questions_sec[$counter][] = $row['question_id'];
    							$counter++;
    						}
    						else
    							if($row['type'] == 'pagebreak')
    							{
    								$counter++;
								} else {
    								// ids from question of the current survey
    								$paged_questions_sec[$counter][] = $row['question_id'];
    							}
    					}
                        $_SESSION['paged_questions_sec'] = $paged_questions_sec;
                    }
                    else
                    {
                    	$paged_questions_sec = $_SESSION['paged_questions_sec'];
                    }
					//print_r($paged_questions_sec);

                    $paged_questions = $_SESSION['paged_questions']; //for the sake of pages counting
                    //$paged_questions = $paged_questions_sec; //for the sake of pages counting coming up at display time...

					if ($shuffle=='')
						$shuffle=' BY survey_question.sort, survey_question_option.sort ASC ';

					//$val=0;
					//if ($survey_data['one_question_per_page']==0)
					//{
						$val=(int)$_POST['personality'];
					//}
					//echo '<pre>';print_r($paged_questions_sec);echo '</pre>';
					if (is_array($paged_questions_sec))
					{
						$sql = "SELECT survey_question.survey_group_sec1, survey_question.survey_group_sec2, survey_question.survey_group_pri,
								survey_question.question_id, survey_question.survey_id, survey_question.survey_question, survey_question.display, survey_question.sort, survey_question.type, survey_question.max_value,
								survey_question_option.question_option_id, survey_question_option.option_text, survey_question_option.sort as option_sort
								FROM $table_survey_question survey_question
								LEFT JOIN $table_survey_question_option survey_question_option
								ON survey_question.question_id = survey_question_option.question_id
								WHERE survey_question.survey_id = '".$my_survey_id."'
								AND survey_question.question_id IN (".implode(',',$paged_questions_sec[$val]).")
								ORDER  $shuffle ";

						$result = Database::query($sql, __FILE__, __LINE__);
						$question_counter_max = Database::num_rows($result);
						$counter = 0;
						$limit=0;
						$questions = array();
						while ($row = Database::fetch_array($result,'ASSOC'))
						{
							// if the type is not a pagebreak we store it in the $questions array
							if ($row['type'] <> 'pagebreak') {
								$questions[$row['sort']]['question_id'] = $row['question_id'];
								$questions[$row['sort']]['survey_id'] = $row['survey_id'];
								$questions[$row['sort']]['survey_question'] = $row['survey_question'];
								$questions[$row['sort']]['display'] = $row['display'];
								$questions[$row['sort']]['type'] = $row['type'];
								$questions[$row['sort']]['options'][$row['question_option_id']] = $row['option_text'];
								$questions[$row['sort']]['maximum_score'] = $row['max_value'];
								// personality params
								$questions[$row['sort']]['survey_group_sec1'] = $row['survey_group_sec1'];
								$questions[$row['sort']]['survey_group_sec2'] = $row['survey_group_sec2'];
								$questions[$row['sort']]['survey_group_pri'] = $row['survey_group_pri'];
							}
							// if the type is a pagebreak we are finished loading the questions for this page
							else
							{
								break;
							}
							$counter++;
						}
					}
					else
					{
						echo get_lang('SurveyUndetermined');
					}

				}
				else
				{
					echo get_lang('SurveyUndetermined');
				}
			}
			else
			{
				echo get_lang('SurveyUndetermined');
			}
		}
		else
		{
			//We need this variable only in the 2nd set of questions when personality is set.
			unset($_SESSION['page_questions_sec']);
			$paged_questions_sec=array();

			// Only the questions from the basic group
			//the 50 questions A B C D E F G
			$order_sql= $shuffle;
			if ($shuffle=='')
				$order_sql=' BY question_id ';

            if(empty($_SESSION['paged_questions']))
            {
    			$sql = "SELECT * FROM $table_survey_question
    						 WHERE survey_id = '".Database::escape_string($survey_invitation['survey_id'])."'
    					  	 AND survey_group_sec1='0' AND survey_group_sec2='0'
    						 ORDER ".$order_sql." ";
    			//echo "<br>";echo "<br>";
    			$result = Database::query($sql, __FILE__, __LINE__);
    			$counter=0;
    			while ($row = Database::fetch_array($result,'ASSOC'))
    			{
    				if ($survey_data['one_question_per_page']==1)
    				{
    					$paged_questions[$counter][] = $row['question_id'];
    					$counter++;
					} else {
						if ($row['type'] == 'pagebreak') {
    						$counter++;
						} else {
    						// ids from question of the current survey
    						$paged_questions[$counter][] = $row['question_id'];
    					}
    				}
    			}
                $_SESSION['paged_questions'] = $paged_questions;
            }
            else
            {
            	$paged_questions = $_SESSION['paged_questions'];
            }

			//print_r($paged_questions);
            //print_r($paged_questions);
			//if (key_exists($_GET['show'],$paged_questions))
			//{
			$order_sql= $shuffle;
			if ($shuffle=='')
				$order_sql=' BY survey_question.sort, survey_question_option.sort ASC ';

			//$val=0;
			//if ($survey_data['one_question_per_page']==0)
			//{
				$val=$_GET['show'];
			//}
			//echo '<pre>';print_r($paged_questions);echo $val;

			$result=null;
			if ($val!='')
			{
				$imploded= implode(',', $paged_questions[$val]);
				if ($imploded!=''){
					// the answers are always in the same order NO shuffle
					$order_sql = ' BY survey_question.sort, survey_question_option.sort ASC ';
					$sql = "SELECT survey_question.survey_group_sec1, survey_question.survey_group_sec2, survey_question.survey_group_pri,
							survey_question.question_id, survey_question.survey_id, survey_question.survey_question, survey_question.display, survey_question.sort, survey_question.type, survey_question.max_value,
							survey_question_option.question_option_id, survey_question_option.option_text, survey_question_option.sort as option_sort
							FROM $table_survey_question survey_question
							LEFT JOIN $table_survey_question_option survey_question_option
							ON survey_question.question_id = survey_question_option.question_id
							WHERE survey_question.survey_id = '" . Database :: escape_string($survey_invitation['survey_id']) . "'
							AND survey_question.question_id IN (" .$imploded. ")
							ORDER $order_sql ";
					$result = Database::query($sql, __FILE__, __LINE__);
					$question_counter_max = Database :: num_rows($result);
				}
			}
			if (!is_null($result))
			{
				$counter = 0;
				$limit = 0;
				$questions = array ();
				while ($row = Database :: fetch_array($result, 'ASSOC'))
				{
					// if the type is not a pagebreak we store it in the $questions array
					if ($row['type'] <> 'pagebreak')
					{
						$questions[$row['sort']]['question_id'] = $row['question_id'];
						$questions[$row['sort']]['survey_id'] = $row['survey_id'];
						$questions[$row['sort']]['survey_question'] = $row['survey_question'];
						$questions[$row['sort']]['display'] = $row['display'];
						$questions[$row['sort']]['type'] = $row['type'];
						$questions[$row['sort']]['options'][$row['question_option_id']] = $row['option_text'];
						$questions[$row['sort']]['maximum_score'] = $row['max_value'];
						// personality params
						$questions[$row['sort']]['survey_group_sec1'] = $row['survey_group_sec1'];
						$questions[$row['sort']]['survey_group_sec2'] = $row['survey_group_sec2'];
						$questions[$row['sort']]['survey_group_pri'] = $row['survey_group_pri'];
					}
					// if the type is a pagebreak we are finished loading the questions for this page
					else {
						break;
					}
					$counter++;
				}
			}
		}
	}
	else //in case it's another type than 0 or 1
    {
        echo get_lang('ErrorSurveyTypeUnknown');
    }
}

// selecting the maximum number of pages
$sql = "SELECT * FROM $table_survey_question WHERE type='".Database::escape_string('pagebreak')."' AND survey_id='".Database::escape_string($survey_invitation['survey_id'])."'";
$result = Database::query($sql, __FILE__, __LINE__);
$numberofpages = Database::num_rows($result) + 1;

if (isset($_GET['show']))
	{
		$show = (int)$_GET['show'] + 1;
	}
	else
	{
		$show = 0;
	}
	echo '<form id="question" name="question" method="post" action="' . api_get_self() .'?'.$add_parameters. 'course=' . $g_c . '&invitationcode=' . $g_ic . '&show=' . $show . '&cidReq=' . $g_cr .$origin_string. '&count='.$brecount.'">';
	echo '<input type="hidden" name="language" value="'.$p_l.'" />';
	echo '<div style="padding-right:20px;">';
	if (($show == 0) )
	{		
		  echo '<button type="submit" name="next_survey_page" class="next">'.get_lang('Validate').'   </button>';
                  
	}else{
		echo '<button type="submit" name="finish_survey" class="next">'.get_lang('FinishSurvey').'  </button>';
	}
	echo '</div><br/><br/>';
	if(is_array($questions) && count($questions)>0)
	{
		foreach ($questions as $key=>$question)
		{	
                       
			echo '<div class="quiz_content_actions" style="width:95%;margin-left:10px;">';	
			$display = new $question['type'];
			$display->render_question($question);
			echo '</div>';	
                        
                       
		}
	}

	echo '</div>'; // close the action div
/*	echo '<div style="padding-right:20px;">';
	if (($show < $numrows))
	{
		//echo '<a href="'.api_get_self().'?survey_id='.$survey_id.'&amp;show='.$limit.'">NEXT</a>';
		echo '<br /><button type="submit" name="next_survey_page" class="next">'.get_lang('Validate').'   </button>';
	}
	if ($show >= $numrows)
	{
		echo '<button type="submit" name="finish_survey" class="next">'.get_lang('FinishSurvey').'  </button>';
	}
	echo '</div>';*/
	echo '</form>';

// Displaying the form with the questions
/*if (isset($_GET['show']))
{
	$show = (int)$_GET['show'] + 1;
}
else
{
	$show = 0;
}*/

// Displaying the form with the questions
if (isset($_POST['personality']))
{
	$personality = (int)$_POST['personality'] + 1;
}
else
{
	$personality= 0;
}





// close the content div
echo '</div>';

 // bottom actions bar
//echo '<div class="actions">';
//echo '</div>';
// Footer
if ($origin != 'learnpath') {
Display::display_footer(); 
}

/**
 * Check if this survey has ended. If so, display message and exit rhis script
 */
function check_time_availability($surv_data){
    $start_date = mktime(0, 0, 0, substr($surv_data['start_date'], 5, 2), substr($surv_data['start_date'], 8, 2), substr($surv_data['start_date'], 0, 4));
    $end_date = mktime(0, 0, 0, substr($surv_data['end_date'], 5, 2), substr($surv_data['end_date'], 8, 2), substr($surv_data['end_date'], 0, 4));
    $cur_date = time();

    if ($cur_date < $start_date) {
        Display :: display_warning_message(get_lang('SurveyNotAvailableYet'), false);
        Display :: display_footer();
        exit;
    }
    if ($cur_date > $end_date)
    {
        Display :: display_warning_message(get_lang('SurveyNotAvailableAnymore'), false);
        Display :: display_footer();
        exit;
    }
}

?>
