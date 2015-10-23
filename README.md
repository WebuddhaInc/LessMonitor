
# PHP Less AutoCompiler

This package can be used to monitor file changes and trigger less compilation.  
Paths are defined for analysis, and the modified timestamp of .less files are
compared to the .css counterpart to determine if a new compilation is requird.

## Usage

    include('WebuddhaInc/LessMonitor/autoload.php');
    $options = array(
      'base_path' => '/home/user/public_html/',
      'trigger_domains' => array(
        'testing.website.com'
        ),
      'watch_paths' => array(
        'themes/default/less'
        ),
      'dependency_exclude' => false,
      'compress' => false    
      );
    $compiler = new WebuddhaInc\LessMonitor\Compiler($options);
    $compiler->execute();

### Runtime Options

The following parameters are available when calling the compiler.

 - `base_path` [string] (optional) the base bath for the public web root
 - `watch_paths` [array] files and folders, relative to the `base_path`, where less files can be found.  *Note: Folders will be scanned recursively.*
 - `trigger_domains` [array] (optional) hostnames that will limit the execution of the compiler.
 - `dependency_exclude` [bool] (optional) exclude wildcard rules when a specific dependency ruleset is defined for the less file.
 - `compress` [bool] (optional) Compress the output and remove comments.

### Import Directives

The compiler does not look within less files for import requirements, rather
import rules are added to a `.lessrc` files, created for a folder or for a 
specific less file.  The modified timestamp for include files is examined to 
determine whether to recompile the less file.

#### Folder wide .lessrc

A `.lessrc` added to a folder can be used to define import requirements for all 
the files within that folder.  The `.lessrc` file is expected to contain a valid 
json object.

 > The comments in the below example are not value JSON

    themes/default/less/.lessrc
    
    {

      // Array of imports for all files
      "import": [
        "../common/bootstrap/*",
        "_variables.less",
        "_mixins.less"
      ],

      // Array of rules for specific files
      "files": [

        // Wildcard Rule
        {
          "file": "*",
          "import": [
            "../common/base.less"
          ]
        },

        // File Specific Rule
        {
          "file": "template.less",
          "import": [
            "../common/fonts.less"
          ]
        }

      ]

    }
 
 Targeted `.lessrc` files can be created for specific less files. 
 
    themes/default/less/template.lessrc
    
    {
      "import": [
        "_variables.less",
        "_mixins.less",
        "../common/fonts.less"
      ]
    }
 
