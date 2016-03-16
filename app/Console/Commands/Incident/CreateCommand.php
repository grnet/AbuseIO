<?php

namespace AbuseIO\Console\Commands\Incident;

use AbuseIO\Console\Commands\ShowHelpWhenRunTimeExceptionOccurs;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputArgument;
use AbuseIO\Jobs\IncidentsProcess;
use Illuminate\Console\Command;
use AbuseIO\Jobs\EvidenceSave;
use AbuseIO\Models\Evidence;
use AbuseIO\Models\Incident;
use Prophecy\Argument;
use Validator;

/**
 * Class CreateCommand
 * @package AbuseIO\Console\Commands\Account
 */
class CreateCommand extends Command
{
    // TODO: Somehow call evidenceProcess(with incident wrapped in array, with evidence build)
    // TODO: Somehow check if the evidence is used (incident did not fail) or remote it if not used
    // TODO: Idea is to make a custom handle() however thats currently not possible due to final functions in abstract

    use ShowHelpWhenRunTimeExceptionOccurs;

    /*
     * Evidence file generated for this incident
     * @var string
     */
    private $evidenceFile;

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return bool
     */
    final public function handle()
    {
        $incident = $this->getModelFromRequest();

        /** @var  $validation */
        $validation = $this->getValidator($incident);
        if ($validation->fails()) {
            foreach ($validation->messages()->all() as $message) {
                $this->error($message);
            }

            $this->error(
                sprintf('Failed to create the %s due to validation warnings', $this->getAsNoun())
            );

            return false;
        }

        /**
         * build evidence model, but wait with saving it
         **/
        $evidence = new Evidence();
        $evidence->filename = $this->evidenceFile;
        $evidence->sender = trim(posix_getpwuid(posix_geteuid())['name']) . ' (CLI)';
        $evidence->subject = 'CLI Created Incident';

        /**
         * Call IncidentsProcess to validate, store evidence and save incidents
         */
        $incidentsProcess = new IncidentsProcess([ $incident ], $evidence);

        // Validate the data set
        if (!$incidentsProcess->validate()) {

            return $this->exception('validation of generated objects failed while processing');
        }

        // Write the data set to database
        if (!$incidentsProcess->save()) {

            return $this->exception('unable to save generated objects');

        }

        $msg = sprintf('The %s has been created', $this->getAsNoun());
        $this->info($msg);

        return true;
    }

    /**
     * Call exception with error message
     * @param $message
     * @return boolean
     */
    private function exception($message)
    {
        $this->error('ERROR: ' . $message);

        return false;
    }

    /**
     * @return InputDefinition
     */
    public function getArgumentsList()
    {
        return new InputDefinition(
            [
                new InputArgument('source', InputArgument::REQUIRED, 'Name of the source'),
                new InputArgument("ip", InputArgument::REQUIRED, "ip address"),
                new InputArgument("domain", InputArgument::REQUIRED, "domain name"),
                new InputArgument('uri', InputArgument::REQUIRED, 'uri or path'),
                new InputArgument("class", InputArgument::REQUIRED, "a preconfigured abuse classification"),
                new InputArgument("type", InputArgument::REQUIRED, "a preconfigured abuse type"),
                new InputArgument("timestamp", InputArgument::REQUIRED, "UNIX timestamp"),
                new InputArgument("information", InputArgument::REQUIRED, "information data in single string or JSON"),
                new InputArgument('file', InputArgument::OPTIONAL, 'Optionally add a file as evidence'),
            ]
        );
    }

    /**
     * {@inheritdoc }
     */
    public function getAsNoun()
    {
        return "incident";
    }

    /**
     * {@inheritdoc }
     */
    protected function getModelFromRequest()
    {
        $incident = new Incident();

        $incident->source = $this->argument('source');
        $incident->ip = $this->argument('ip');
        $incident->domain = $this->argument('domain');
        $incident->uri = $this->argument('uri');
        $incident->class = $this->argument('class');
        $incident->type = $this->argument('type');
        $incident->timestamp = $this->argument('timestamp');

        if (is_object(json_decode($this->argument('information')))) {
            // JSON object given which we can directly use in the incident
            $incident->information = $this->argument('information');

        } else {
            // String given so wrapping it into a data json object
            $incident->information = json_encode(
                [
                    'data' => $this->argument('information'),
                ]
            );
        }

        /*
         * Save the evidence as its required to save incidents
         */
        $evidence = new EvidenceSave;
        $evidenceData = [
            'CreatedBy'     => trim(posix_getpwuid(posix_geteuid())['name']) . ' (CLI)',
            'receivedOn'    => time(),
            'submittedData' => $incident->toArray(),
            'attachments'   => [],
        ];

        // Add the file to evidence object if it was given
        if ($this->argument('file') !== null) {
            // Build evidence with added file
            if (!is_file($this->argument('file'))) {
                $this->error('File does not exist: ' . $this->argument('file'));
                die();
            }

            $attachment = [
                'filename' => basename($this->argument('file')),
                'size' => filesize($this->argument('file')),
                'contentType' => mime_content_type($this->argument('file')),
                'data' => file_get_contents($this->argument('file'))
            ];
            $evidenceData['attachments'][] = $attachment;
        }

        $this->evidenceFile = $evidence->save(json_encode($evidenceData));

        if (empty($this->evidenceFile)) {
            $this->error('Error returned while asking to write evidence file, cannot continue');
            die();
        }

        return $incident;
    }

    /**
     * {@inheritdoc }
     */
    protected function getValidator($model)
    {
        return Validator::make($model->toArray(), Incident::createRules());
    }

    /**
     * Configure the console command.
     */
    final protected function configure()
    {
        $this
            ->setName($this->getName())
            ->setDescription($this->getDescription())
            ->setDefinition(
                $this->getArgumentsList()
            );
    }

    /**
     * @return string
     */
    final public function getName()
    {
        return sprintf('%s:%s', $this->getAsNoun(), $this->getCommandName());
    }

    /**
     * Default subcommand name.
     *
     * @return string
     */
    final public function getCommandName()
    {
        if (!empty($this->commandName)) {
            return $this->commandName;
        }

        return 'create';
    }

    /**
     * @return string
     */
    final public function getDescription()
    {
        if (!empty($this->commandDescription)) {
            return $this->commandDescription;
        }

        return sprintf('Creates a new %s', $this->getAsNoun());
    }
}
