<?php

$config = loadconfig('/var/www/uniquip/config.json');

switch ($_SERVER['REQUEST_METHOD'])
{
	case 'POST':
		process_post();
		break;
	case 'GET':
		process_get();
		break;
}


#a file is being posted.  Institution is specified with the
#'institution' parameter.
function process_post()
{
	$institution = $_POST['institution'];
	
	if (!valid_institution($institution))
	{
		#return a status code and exit
	}

	$username = $_SERVER['REMOTE_USER'];

	if (!valid_user($username))
	{
		#return a status code and exit
	}

	if (!allow($username, $institution, 'write'))
	{
		#return a status code and exit
	}

	if (!$_FILES['file'])
	{
		#return a status code and exit
	}

	#upload file
	upload_posted_file($institution,$username);
}

function upload_posted_file($institution,$username)
{
	global $config;

	$upload_dir = $config['institutions'][$institution]['upload_dir'];
	$tmp_file = tempnam($upload_dir,'NEW');

	$tmp_name = $_FILES["file"]["tmp_name"];
	move_uploaded_file($tmp_name, $tmp_file);

	if (file_is_valid($tmp_name))
	{
		
	}
}



function file_is_valid($file_path)
{
	global $config;



}


#a user is attempting to read or write to a file.  Can they?
#File names are either institution names or 'combined' for the combined file
function allow($username, $file, $action)
{
	global $config;

	$permissions = $config["users"][$username]["allow_$action"];

	if (in_array($institution, $permissions) || in_array($institution, 'all'))
	{
		return true;
	}
	return false;
}


function process_get()
{
	if (isset($_GET["file"]))
	{
		#check permissions and send the file

		
	}
	else
	{
		#roll back to sending a simple upload form.
		output_query_form();
	}
}


function log_event($event_type, $msg)
{
	global $config;

	$log_dir = $config["system"]["base_path"];
	$log_dir .= $config["system"]["log_base"];
	create_dir_if_needed($log_dir);
	$log_file = $log_dir . $config["system"]["logfile_name"];

	$fp = fopen($log_file, 'w');

	if (!$fp)
	{
		die "Couldn't open $log_file for writing\n";
	}


	$log_parts = array();

	array_push($log_parts, $event_type);
	array_push($log_parts, date("Y-m-d H:i:s"));
	array_push($log_parts, $_SERVER['REMOTE_HOST');
	array_push($log_parts, $msg);

	$log_line = implode("\t",$log_parts) . "\n";

	if (!fwrite($fp, $log_line))
	{
		die "Couldn't write to $log_file\n";
	}

	fclose($fp);
}


function output_query_form()
{
	$page = '
<html>
<head>
<title>Please upload a file</title>
</head>
<body>
<form method="post" action="upload.php" enctype="multipart/form-data">
<label for="file">File:</label>
<input type="file" name="file" id="file" />
<br />
<label for="institution">Institution:</label>
<select name="institution">';

	$page .= institution_select_options();

	$page .= '
</select>
<br/>
<input type="submit" name="submit" value="Submit" />
</form>
</body>
</html>
';
	echo $page;
}

function institution_select_options()
{
	global $config;

	$options = '<option value="" selected="selected">Select an Institution...</option>';

	asort($config['institutions']);
	foreach ($config['institutions'] as $id => $info)
	{
		$options .= '<option value="' . $id . '">' . $info['name'] . '</option>';
	}

	return $options;
}

function valid_institution($institution)
{
	global $config;

	return array_key_exists($institution, $config["institutions"]);
}

function valid_username($institution)
{
	global $config;

	return array_key_exists($institution, $config["users"]);
}



function loadconfig($filename)
{

	$data = file_get_contents($filename);

	if ($data == NULL)
	{
		$msg = "Couldn't open $filename";
		log_event('ERROR',$msg);
		die("$msg\n");
	}

	$decoded = json_decode($data,1);

	if ($decoded == NULL)
	{
		$msg = "Couldn't open $filename";
		log_event('ERROR',$msg);
		die("$msg\n");
	}

	$upload_base = $decoded["system"]["base_path"];
	$upload_base .= $decoded["system"]["upload_base"];

	#Set each institution's upload directorory
	#Create all upload directories if they're needed
	foreach ($decoded["institutions"] as $id => &$info)
	{
		$upload_dir = $upload_base . $id . '/';
		$info["upload_dir"] = $upload_dir;
		create_dir_if_needed($upload_dir);
	}

	return $decoded;
}

function create_dir_if_needed($path)
{
	if (is_dir($path)) { return; }

	if (!mkdir($path,0770,1))
	{
		$msg = "couldn't create $path\n";
		log_event('ERROR',$msg);
		die("$msg\n");
	}
}

?>
