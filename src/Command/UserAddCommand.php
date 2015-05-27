<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class UserAddCommand extends UserCommand
{

    protected function configure()
    {
        $this
          ->setName('user:add')
          ->setDescription('Add a user to the project')
          ->addArgument('email', InputArgument::OPTIONAL, "The new user's email address")
          ->addOption('role', null, InputOption::VALUE_OPTIONAL, "The new user's role: 'admin' or 'viewer'");
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        $email = $input->getArgument('email');
        if ($email && !$this->validateEmail($email)) {
            return 1;
        }
        elseif (!$email) {
            $question = new Question('Email address: ');
            $question->setValidator(array($this, 'validateEmail'));
            $question->setMaxAttempts(5);
            $email = $questionHelper->ask($input, $output, $question);
        }

        $project = $this->getSelectedProject();

        $users = $project->getUsers();
        foreach ($users as $user) {
            if ($user->getAccount()['email'] === $email) {
                $this->stdErr->writeln("The user already exists: <comment>$email</comment>");
                return 1;
            }
        }

        $projectRole = $input->getOption('role');
        if ($projectRole && !in_array($projectRole, array('admin', 'viewer'))) {
            $this->stdErr->writeln("Valid project-level roles are 'admin' or 'viewer'");
            return 1;
        }
        elseif (!$projectRole) {
            if (!$input->isInteractive()) {
                $this->stdErr->writeln('You must specify a project role for the user.');
                return 1;
            }
            $this->stdErr->writeln("The user's project role can be 'viewer' ('v') or 'admin' ('a').");
            $question = new Question('Project role <question>[V/a]</question>: ', 'viewer');
            $question->setValidator(array($this, 'validateRole'));
            $question->setMaxAttempts(5);
            $projectRole = $this->standardizeRole($questionHelper->ask($input, $this->stdErr, $question));
        }

        $environmentRoles = [];
        $environments = [];
        if ($projectRole !== 'admin') {
            $environments = $this->getEnvironments($project);
            if ($input->isInteractive()) {
                $this->stdErr->writeln("The user's environment-level roles can be 'viewer', 'contributor', or 'admin'.");
            }
            foreach ($environments as $environment) {
                $question = new Question('<info>' . $environment->id . '</info> environment role <question>[V/c/a]</question>: ', 'viewer');
                $question->setValidator(array($this, 'validateRole'));
                $question->setMaxAttempts(5);
                $environmentRoles[$environment->id] = $this->standardizeRole($questionHelper->ask($input, $this->stdErr, $question));
            }
        }

        $summaryFields = [
            'Email address' => $email,
            'Project role' => $projectRole,
        ];
        if (!empty($environmentRoles)) {
            foreach ($environments as $environment) {
                if (isset($environmentRoles[$environment->id])) {
                    $summaryFields[$environment['title']] = $environmentRoles[$environment->id];
                }
            }
        }

        $this->stdErr->writeln('Summary:');
        foreach ($summaryFields as $field => $value) {
            $this->stdErr->writeln("    $field: <info>$value</info>");
        }

        $this->stdErr->writeln("<comment>Adding users can result in additional charges.</comment>");

        if ($input->isInteractive()) {
            if (!$questionHelper->confirm("Are you sure you want to add this user?", $input, $this->stdErr)) {
                return 1;
            }
        }

        $project->addUser($email, $projectRole);

        $this->stdErr->writeln("User <info>$email</info> created");
        return 0;
    }

}
