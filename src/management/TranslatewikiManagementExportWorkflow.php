<?php

final class TranslatewikiManagementExportWorkflow
  extends TranslatewikiManagementWorkflow {

  private $browseURI;

  protected function didConstruct() {
    $this
      ->setName('export')
      ->setExamples('**export** [options] __library__')
      ->setSynopsis(
        pht(
          'Export translation strings from a libphutil library.'))
      ->setArguments(
        array(
          array(
            'name' => 'as',
            'param' => 'name',
            'help' => pht(
              'Name for the project being exported. Exported files will be '.
              'written to "projects/" using this name.'),
          ),
          array(
            'name' => 'browse-uri',
            'param' => 'uri',
            'help' => pht(
              'Base URI for browsing files in the project being exported.'),
          ),
          array(
            'name' => 'library',
            'wildcard' => true,
          ),
        ));
  }

  public function execute(PhutilArgumentParser $args) {
    $library = $args->getArg('library');
    if (!$library) {
      throw new PhutilArgumentUsageException(
        pht(
          'Provide the path to a library to export translations from.'));
    }

    if (count($library) > 1) {
      throw new PhutilArgumentUsageException(
        pht(
          'Provide the path to exactly one library to export translations '.
          'from.'));
    }

    $as = $args->getArg('as');
    if (!strlen($as)) {
      throw new PhutilArgumentUsageException(
        pht(
          'Provide a project name to export strings under with "--as".'));
    }

    $this->browseURI = $args->getArg('browse-uri');

    $phabricator_root = phutil_get_library_root('phabricator');
    $i18n_bin = $phabricator_root.'/../bin/i18n';

    $export_root = head($library);

    echo tsprintf(
      "%s\n",
      pht('Extracting library strings...'));

    phutil_passthru(
      '%R extract %R',
      $i18n_bin,
      $export_root);

    $strings_path = $export_root.'/.cache/i18n_strings.json';
    if (!Filesystem::pathExists($strings_path)) {
      throw new Exception(
        pht(
          'Expected library string extraction to genrate file "%s", but '.
          'no such file exists!',
          $strings_path));
    }

    $strings_data = Filesystem::readFile($strings_path);
    $strings_data = phutil_json_decode($strings_data);

    echo tsprintf(
      "%s\n",
      pht(
        'Read %s string(s).',
        count($strings_data)));

    $result_en = array();
    $result_qqq = array();
    $result_raw = array();
    foreach ($strings_data as $string => $spec) {
      $string_key = $this->getStringKey($string);

      $translatewiki_string = $this->getTranslatewikiString(
        $string,
        $spec);

      if ($translatewiki_string === null) {
        continue;
      }

      $result_en[$string_key] = $translatewiki_string;
      $result_raw[$string_key] = $string;
      $result_qqq[$string_key] = $this->getTranslatewikiContext(
        $string,
        $spec);
    }

    $translatewiki_root = phutil_get_library_root('translatewiki');
    $projects_root = "{$translatewiki_root}/../projects/{$as}/";

    $writes = array(
      array(
        'name' => 'en.json',
        'data' => $result_en,
        'help' => pht('English strings'),
      ),
      array(
        'name' => 'qqq.json',
        'data' => $result_qqq,
        'help' => pht('Context strings'),
      ),
      array(
        'name' => 'raw.json',
        'data' => $result_raw,
        'help' => pht('Raw strings'),
      ),
    );

    Filesystem::createDirectory($projects_root, 0755, true);
    foreach ($writes as $write) {
      $path = $projects_root.$write['name'];

      echo tsprintf(
        "%s\n",
        pht(
          'Writing data (%s) to "%s"...',
          $write['help'],
          Filesystem::readablePath($path)));

      $data = $write['data'];

      ksort($data);

      $data = id(new PhutilJSON())
        ->encodeFormatted($data);

      Filesystem::writeFile($path, $data);
    }

    echo tsprintf(
      "%s\n",
      pht('Done.'));

    return 0;
  }

  private function getStringKey($string) {
    return substr(sha1($string), 0, 16);
  }

  private function getTranslatewikiString($string, array $spec) {
    $string = (string)$string;

    // We're going to convert all "%%" (literal percent symbol) to "%".
    // We're going to convert all "%s", "%d", etc., to "$1", "$2", etc.

    $pattern = '/(\%.)|(\\$)/';
    $matches = null;
    $count = preg_match_all(
      $pattern,
      $string,
      $matches,
      PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE);

    $n = 1;
    $adjust = 0;

    if ($count) {
      foreach ($matches[1] as $pattern_hit) {
        if (!$pattern_hit) {
          continue;
        }

        $text = $pattern_hit[0];
        $offset = $pattern_hit[1];

        if ($offset == -1) {
          continue;
        }

        $replacement = null;
        switch ($text) {
          case '%%':
            $replacement = '%';
            break;
          case '%d':
          case '%s':
            $replacement = '$'.$n;
            $n++;
            break;
          default:
            echo tsprintf(
              "%s\n",
              pht(
                'Unable to extract string with unrecognized "%%" pattern, '.
                '"%s": %s.',
                $text,
                $string));
            return null;
        }

        if ($replacement !== null) {
          $string = substr_replace(
            $string,
            $replacement,
            $offset + $adjust,
            strlen($text));
          $adjust += strlen($replacement) - strlen($text);
        }
      }

      foreach ($matches[2] as $dollar_hit) {
        if (!$dollar_hit) {
          continue;
        }

        $text = $dollar_hit[0];
        $offset = $dollar_hit[1];

        if ($offset == -1) {
          continue;
        }

        echo tsprintf(
          "%s\n",
          pht(
            'Unable to extract string containing "$" symbol: %s',
            $string));
        return null;
      }
    }

    return (string)$string;
  }

  private function getTranslatewikiContext($string, array $spec) {
    $help = array();

    $usage = array();
    foreach ($spec['uses'] as $use) {
      $name = basename($use['file']);
      $line = $use['line'];

      if ($this->browseURI) {
        $uri = $this->browseURI.$use['file'].'$'.$line;
      } else {
        $uri = null;
      }

      if ($uri) {
        $usage[] = "[{$uri} {$name}:{$line}]";
      } else {
        $usage[] = $name.':'.$line;
      }
    }

    if ($usage) {
      $help[] = pht('Used in:');
      $help[] = "\n\n";
      $help[] = implode("\n", $usage)."\n";
    }

    return implode('', $help);
  }

}
