<?php
define('CLI_SCRIPT', true);

require(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once($CFG->libdir.'/environmentlib.php');
if (empty($CFG->version)) {
    util_cli_error('Config table does not contain version, can not continue, sorry.');
}

$major = (float) normalize_version($CFG->release);
if ($major < 2){
    util_cli_error('Moodle 2.x.x required to run this script');
}
// now get cli options
list($options, $unrecognized) = util_cli_get_params(
    array(
        'import'=> false,
        'export'=> false,
        'help'=> false
    ),
    array(
        'h' => 'help'
    )
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    util_cli_error("Unrecognised options: {$unrecognized} Please use --help option.");
}

if ($options['help'] || (count(array_unique(array_values($options))) == 1)) {
    $help =
"Role definitions import/export tool.

Move role definitions between instances, uses XML to packaging

Please note you must execute this script with the same uid as apache!

Options:
--import=FILE         Import a role(s) definition
--export=FILE         Export a role(s) definition
-h, --help            Print out this help

Example:
\$sudo -u apache /usr/bin/php admin/cli/roledefinitions_ietool.php
";

    echo $help;
    die;
}

if ($options['import'] && $options['export']) {
    util_cli_error('Cannot export and import in same operation, sorry.');
}
// Get system context will be used throughtout script
$systemcontext = context_system::instance();
// Export
if ($options['export']) {
    $roleoptions = array();
    $selectroles = get_all_roles();
    $prompt = "select a role to export";
    $prompt .= "\n".'A [ALL]';
    $roleoptions[] = 'a';
    foreach ($selectroles as $selectrole) {
        $prompt .= "\n".$selectrole->id.' '.$selectrole->name. '['.$selectrole->shortname.']';
        $roleoptions[] = $selectrole->id;
    }
    // Filter input selection
    $input = util_cli_input($prompt, '', $roleoptions); // confirm
    $roles = array();
    if ($input == 'a') {
        $roles = $selectroles;
    } else {
        $roles[$input] = $selectroles[$input];
    }
    // Start building XML
    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->formatOutput = true;
    $rootnode = $dom->createElement('roles_definition');
    $rootnode->setAttributeNode(new DOMAttr('version', $CFG->version));
    $rootnode->setAttributeNode(new DOMAttr('release', $CFG->release));
    $dom->appendChild($rootnode);
    // This could be done better but in a rush cutnpaste
    foreach ($roles as $id => $role) {
        $rolenode = $dom->createElement('role');
        $rootnode->appendChild($rolenode);
        $rolenode->appendChild($dom->createElement('name', $roles[$id]->name));
        $rolenode->appendChild($dom->createElement('shortname', $roles[$id]->shortname));
        $rolenode->appendChild($dom->createElement('description', $roles[$id]->description));
        $rolenode->appendChild($dom->createElement('archetype', $roles[$id]->archetype));

        // contextlevels
        $contextlevels = get_role_contextlevels($id);
        $contextlevelsnode = $dom->createElement('contextlevels');
        $rolenode->appendChild($contextlevelsnode);
        foreach ($contextlevels as $contextlevel) {
            $contextlevelnode = $dom->createElement('contextlevel', $contextlevel);
            $contextlevelsnode->appendChild($contextlevelnode);
        }
        // capabilities
        $capabilities = get_capabilities_from_role_on_context($roles[$id], $systemcontext);
        $capabilitiesnode = $dom->createElement('capabilities');
        $rolenode->appendChild($capabilitiesnode);
        foreach ($capabilities as $capability) {
            $capabilitynode = $dom->createElement('capability');
            $capabilitynode->setAttributeNode(new DOMAttr('name', $capability->capability));
            $capabilitynode->setAttributeNode(new DOMAttr('permission', $capability->permission));
            $capabilitiesnode->appendChild($capabilitynode);
        }
    }

    $file = $options['export'];
    if ($file == 1) { // no file
        echo $dom->saveXML();
    } else {
        $dom->save($file);
        mtrace('File exported to: '.$file);
    }
    exit(0);

}
// Import
if ($options['import']) {
    $file = $options['import'];
    if (!is_readable($file)) {
        util_cli_error('Cannot read file, sorry.');
    }
    $rolesimport = array();
    $doc = new DOMDocument();
    $doc->load($file);
    $roles = $doc->getElementsByTagName('role');
    foreach ($roles as $role) {
        $roleimport = new stdClass();
        $names = $role->getElementsByTagName('name');
        $name = $names->item(0)->nodeValue;
        $shortnames = $role->getElementsByTagName('shortname');
        $shortname = $shortnames->item(0)->nodeValue;
        $descriptions = $role->getElementsByTagName('description');
        $description = $descriptions->item(0)->nodeValue;
        $archetypes = $role->getElementsByTagName('archetype');
        $archetype = $archetypes->item(0)->nodeValue;
        // build base info
        $roleimport->shortname = $shortname;
        $roleimport->name = $name;
        $roleimport->description = $description;
        $roleimport->archetype = $archetype;

        $roleimport->contextlevels = array();
        $contextlevels = $role->getElementsByTagName('contextlevel');
        foreach ($contextlevels as $contextlevel) {
            $roleimport->contextlevels[$contextlevel->nodeValue] = $contextlevel->nodeValue;
        }
        $roleimport->capabilities = array();
        $capabilities = $role->getElementsByTagName('capability');
        foreach ($capabilities as $capability) {
            if ($capability->hasAttributes()) {
                $attributes = $capability->attributes;
                $capabilityimport = new stdClass();
                foreach ($attributes as $name => $node) {
                    if ($name == 'name') {
                        $capabilityimport->name = $node->value;
                    }
                    if ($name == 'permission') {
                        $capabilityimport->permission  = $node->value;
                    }
                }
                $roleimport->capabilities[$capabilityimport->name] = $capabilityimport;
            }
        }
        $rolesimport[$roleimport->shortname] = $roleimport;
    }
    unset($doc);

    // Right time to add/update Moodle roles.
    foreach ($rolesimport as $roleimport) {
        mtrace($roleimport->name);
        $existingrole = $DB->get_record('role', array('shortname'=>$roleimport->shortname));
        if ($existingrole) {
            $prompt = 'Found matching role '.$existingrole->name.', overwrite capabilities and permissions? type y (means yes) or n (means no)';
            $input = util_cli_input($prompt, '', array('n', 'y'));
            if ($input == 'n') {
                continue;
            }
            mtrace('setting role contextlevels');
            set_role_contextlevels($existingrole->id, $roleimport->contextlevels);
            //reset_role_capabilities($existingrole->id);// reset
            foreach ($roleimport->capabilities as $cap) {
                mtrace('setting capability: '.$cap->name);
                assign_capability($cap->name, $cap->permission, $existingrole->id, $systemcontext->id, true);
            }
            context_system::instance()->mark_dirty();
            mtrace($existingrole->name.' import finished');
        } else {
            $prompt = 'Create role '.$roleimport->name.'? type y (means yes) or n (means no)';
            $input = util_cli_input($prompt, '', array('n', 'y'));
            if ($input == 'n') {
                continue;
            }
            $roleimport->id = create_role($roleimport->name, $roleimport->shortname, $roleimport->description, $roleimport->archetype);
            if (!$roleimport->id) {
                mtrace('failed to create role, sorry');
                exit(0);
            }
            mtrace('setting role contextlevels');
            set_role_contextlevels($roleimport->id, $roleimport->contextlevels);
            foreach ($roleimport->capabilities as $cap) {
                mtrace('setting capability: '.$cap->name);
                assign_capability($cap->name, $cap->permission, $roleimport->id, $systemcontext->id, true);
            }
            context_system::instance()->mark_dirty();
            mtrace($roleimport->name.' import finished');
        }
    }
    mtrace('done!');
    exit(0);
}



/**
 * Command line utility functions and classes
 *
 * @package    core
 * @subpackage cli
 * @copyright  2009 Petr Skoda (http://skodak.org)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Get input from user
 * @param string $prompt text prompt, should include possible options
 * @param string $default default value when enter pressed
 * @param array $options list of allowed options, empty means any text
 * @param bool $casesensitive true if options are case sensitive
 * @return string entered text
 */
function util_cli_input($prompt, $default='', array $options=null, $casesensitiveoptions=false) {
    echo $prompt;
    echo "\n: ";
    $input = fread(STDIN, 2048);
    $input = trim($input);
    if ($input === '') {
        $input = $default;
    }
    if ($options) {
        if (!$casesensitiveoptions) {
            $input = strtolower($input);
        }
        if (!in_array($input, $options)) {
            echo "Incorrect value, please retry.\n"; // TODO: localize, mark as needed in install
            return util_cli_input($prompt, $default, $options, $casesensitiveoptions);
        }
    }
    return $input;
}

/**
 * Returns cli script parameters.
 * @param array $longoptions array of --style options ex:('verbose'=>false)
 * @param array $shortmapping array describing mapping of short to long style options ex:('h'=>'help', 'v'=>'verbose')
 * @return array array of arrays, options, unrecognised as optionlongname=>value
 */
function util_cli_get_params(array $longoptions, array $shortmapping=null) {
    $shortmapping = (array)$shortmapping;
    $options      = array();
    $unrecognized = array();

    if (empty($_SERVER['argv'])) {
        // bad luck, we can continue in interactive mode ;-)
        return array($options, $unrecognized);
    }
    $rawoptions = $_SERVER['argv'];

    //remove anything after '--', options can not be there
    if (($key = array_search('--', $rawoptions)) !== false) {
        $rawoptions = array_slice($rawoptions, 0, $key);
    }

    //remove script
    unset($rawoptions[0]);
    foreach ($rawoptions as $raw) {
        if (substr($raw, 0, 2) === '--') {
            $value = substr($raw, 2);
            $parts = explode('=', $value);
            if (count($parts) == 1) {
                $key   = reset($parts);
                $value = true;
            } else {
                $key = array_shift($parts);
                $value = implode('=', $parts);
            }
            if (array_key_exists($key, $longoptions)) {
                $options[$key] = $value;
            } else {
                $unrecognized[] = $raw;
            }

        } else if (substr($raw, 0, 1) === '-') {
            $value = substr($raw, 1);
            $parts = explode('=', $value);
            if (count($parts) == 1) {
                $key   = reset($parts);
                $value = true;
            } else {
                $key = array_shift($parts);
                $value = implode('=', $parts);
            }
            if (array_key_exists($key, $shortmapping)) {
                $options[$shortmapping[$key]] = $value;
            } else {
                $unrecognized[] = $raw;
            }
        } else {
            $unrecognized[] = $raw;
            continue;
        }
    }
    //apply defaults
    foreach ($longoptions as $key=>$default) {
        if (!array_key_exists($key, $options)) {
            $options[$key] = $default;
        }
    }
    // finished
    return array($options, $unrecognized);
}

/**
 * Print or return section separator string
 * @param bool $return false means print, true return as string
 * @return mixed void or string
 */
function util_cli_separator($return=false) {
    $separator = str_repeat('-', 79)."\n";
    if ($return) {
        return $separator;
    } else {
        echo $separator;
    }
}

/**
 * Print or return section heading string
 * @param string $string text
 * @param bool $return false means print, true return as string
 * @return mixed void or string
 */
function util_cli_heading($string, $return=false) {
    $string = "== $string ==\n";
    if ($return) {
        return $string;
    } else {
        echo $string;
    }
}

/**
 * Write error notification
 * @param $text
 * @return void
 */
function util_cli_problem($text) {
    fwrite(STDERR, $text."\n");
}

/**
 * Write to standard out and error with exit in error.
 *
 * @param string $text
 * @param int $errorcode
 * @return void (does not return)
 */
function util_cli_error($text, $errorcode=1) {
    fwrite(STDERR, $text);
    fwrite(STDERR, "\n");
    die($errorcode);
}

?>
