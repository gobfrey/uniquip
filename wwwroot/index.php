<?php

$config = loadconfig('/var/www/equipment/config.json');

switch ($_SERVER['REQUEST_METHOD'])
{
	case 'POST':
		process_post();
		break;
	case 'GET':
		process_get();
		break;
	case 'PUT':
		process_put();
		break;
}


#a file is being posted.  Institution is specified with the
#'institution' parameter.
function process_post()
{
	$institution = $_POST['institution'];
	


	if (!valid_institution($institution))
	{
	}
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

function process_put()
{
	


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
	return in_array($institution, $config["institutions"]);
}


function loadconfig($filename)
{

	$data = file_get_contents($filename);

	if ($data == NULL)
	{
		die("Couldn't open $filename\n");
	}

	$decoded = json_decode($data,1);

	if ($decoded == NULL)
	{
		die("Couldn't parse $filename\n");
	}

	$upload_base = $decoded["system"]["base_path"];
	$upload_base .= $decoded["system"]["upload_base"];

	#Set each institution's upload directorory
	#Create all upload directories if they're needed
	foreach ($decoded["institutions"] as $id => &$info)
	{
		$upload_dir = $upload_base . $id . '/';
		$info["upload_dir"] = $upload_dir;
		if (!is_dir($upload_dir))
		{
			if (!mkdir($upload_dir,0770,1))
			{
				die("couldn't create $upload_dir\n");
			}
		}
	}

	return $decoded;
}



?>
