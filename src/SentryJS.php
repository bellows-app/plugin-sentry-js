<?php

namespace Bellows\Plugins;

use Bellows\PluginSdk\Contracts\Deployable;
use Bellows\PluginSdk\Contracts\HttpClient;
use Bellows\PluginSdk\Contracts\Installable;
use Bellows\PluginSdk\Data\AddApiCredentialsPrompt;
use Bellows\PluginSdk\Facades\Console;
use Bellows\PluginSdk\Facades\Deployment;
use Bellows\PluginSdk\Facades\Npm;
use Bellows\PluginSdk\Facades\Project;
use Bellows\PluginSdk\Plugin;
use Bellows\PluginSdk\PluginResults\CanBeDeployed;
use Bellows\PluginSdk\PluginResults\CanBeInstalled;
use Bellows\PluginSdk\PluginResults\DeploymentResult;
use Bellows\PluginSdk\PluginResults\InstallationResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;

class SentryJS extends Plugin implements Deployable, Installable
{
    use CanBeDeployed, CanBeInstalled;

    protected string $jsFramework = 'js';

    protected array $organization;

    protected ?string $sentryClientKey;

    protected ?float $tracesSampleRate = null;

    protected Collection $projects;

    public function __construct(
        protected HttpClient $http,
    ) {
    }

    public function setupClient(): void
    {
        $this->http->createJsonClient(
            'https://sentry.io/api/',
            fn (PendingRequest $request, array $credentials) => $request->withToken($credentials['token']),
            new AddApiCredentialsPrompt(
                url: 'https://sentry.io/settings/account/api/auth-tokens/',
                credentials: ['token'],
                helpText: 'When creating a token, make sure to select the following permissions: project:read, team:read, project:write, org:read, member:read',
                displayName: 'Sentry',
            ),
            fn (PendingRequest $request) => $request->get('0/projects/', ['per_page' => 1]),
            true,
        );

        $this->organization = $this->http->client()->get('0/organizations/')->json()[0];
    }

    public function install(): ?InstallationResult
    {
        $jsFramework = Console::choice('Which JS framework are you using?', ['Vue', 'React', 'Neither']);

        $this->jsFramework = match ($jsFramework) {
            'Vue'   => 'vue',
            'React' => 'react',
            default => 'js',
        };

        $result = InstallationResult::create()->npmPackages(
            match ($this->jsFramework) {
                'vue' => [
                    '@sentry/vue',
                ],
                'react' => [
                    '@sentry/react',
                ],
                default => [],
            }
        )->copyDirectory(__DIR__ . '/../frameworks/' . $this->jsFramework);

        if (Console::confirm('Setup Sentry JS project now?', false)) {
            $this->setupSentry();

            $result->environmentVariables($this->environmentVariables());
        }

        return $result;
    }

    public function deploy(): ?DeploymentResult
    {
        $this->sentryClientKey = Project::env()->get('SENTRY_JS_DSN');

        if ($this->sentryClientKey) {
            Console::miniTask('Using existing Sentry JS client key from', '.env');
        } else {
            $this->setupSentry();
        }

        return DeploymentResult::create()->environmentVariables($this->environmentVariables());
    }

    public function anyRequiredNpmPackages(): array
    {
        return [
            '@sentry/vue',
            '@sentry/react',
        ];
    }

    public function shouldDeploy(): bool
    {
        return !Deployment::site()->env()->hasAll('SENTRY_JS_DSN', 'VITE_SENTRY_JS_DSN');
    }

    public function installWrapUp(): void
    {
        if ($this->jsFramework === 'js') {
            return;
        }
    }

    public function environmentVariables(): array
    {
        $params = [
            'SENTRY_JS_DSN'      => $this->sentryClientKey,
            'VITE_SENTRY_JS_DSN' => '${SENTRY_JS_DSN}',
        ];

        if ($this->tracesSampleRate !== null) {
            $params['SENTRY_JS_TRACES_SAMPLE_RATE'] = $this->tracesSampleRate;
            $params['VITE_SENTRY_JS_TRACES_SAMPLE_RATE'] = '${SENTRY_JS_TRACES_SAMPLE_RATE}';
        }

        return $params;
    }

    protected function createProject(): array
    {
        $teams = collect($this->http->client()->get("0/organizations/{$this->organization['slug']}/teams/")->json());

        $team = Console::choiceFromCollection(
            'Select a team',
            $teams->sortBy('name'),
            'name',
            $teams->count() === 1 ? $teams->first()['name'] : null,
        );

        $name = Console::ask('Project name', Project::appName());

        $project = $this->http->client()->post("0/teams/{$this->organization['slug']}/{$team['slug']}/projects/", [
            'name'     => $name,
            'platform' => $this->getProjectType(),
        ])->json();

        return $project;
    }

    protected function getProject(): ?array
    {
        $projectType = $this->getProjectType();

        $result = $this->http->client()->get('0/projects/', [
            'per_page' => 100,
        ])->json();

        $this->projects = collect($result)->filter(fn ($project) => $project['platform'] === $projectType)->values();

        if ($this->projects->count() > 0 && $this->projects->first(fn ($p) => $p['name'] === Project::appName())) {
            // If we have projects and one of them matches the name of the app, suggest that one
            return $this->selectFromExistingProjects($projectType);
        }

        if (Console::confirm('Create Sentry project?', true)) {
            return $this->createProject();
        }

        if ($this->projects->count() > 0) {
            return $this->selectFromExistingProjects($projectType);
        }

        Console::error("No existing {$projectType} projects found!");

        if (Console::confirm('Create Sentry project?', true)) {
            // Give them one more chance to create a project
            return $this->createProject();
        }

        Console::error('No project selected! Disabling Sentry plugin.');

        return null;
    }

    protected function setupSentry()
    {
        $this->setupClient();
        $this->setClientKey();
        $this->setTracesSampleRate();
    }

    protected function setTracesSampleRate(): void
    {
        if (!isset($this->sentryClientKey)) {
            return;
        }

        $projectType = $this->getProjectType();
        $language = collect(explode('-', $projectType))->first();

        Console::info('To enable performance monitoring, set a number greater than 0.0 (max 1.0).');
        Console::info('Leave blank to disable.');
        Console::newLine();
        Console::info("More info: https://docs.sentry.io/platforms/{$language}/performance/");

        try {
            $this->tracesSampleRate = Console::ask('Traces Sample Rate');
        } catch (\Throwable $e) {
            Console::error('Invalid value!');
            Console::newLine();

            $this->setTracesSampleRate();

            return;
        }

        if ($this->tracesSampleRate === null) {
            return;
        }

        if (!is_numeric($this->tracesSampleRate) || $this->tracesSampleRate < 0 || $this->tracesSampleRate > 1) {
            $this->setTracesSampleRate();
        }
    }

    protected function setClientKey()
    {
        $project = $this->getProject();

        if ($project === null) {
            return;
        }

        $keys = collect($this->http->client()->get("0/projects/{$this->organization['slug']}/{$project['slug']}/keys/")->json());

        $key = Console::choiceFromCollection(
            'Select a client key',
            $keys->sortBy('name'),
            'name',
        );

        $this->sentryClientKey = $key['dsn']['public'];
    }

    protected function selectFromExistingProjects(string $type): array
    {
        $choices = $this->projects->sortBy('name')->concat([['name' => 'Create new project']]);

        $selection = Console::choiceFromCollection(
            'Select a Sentry project',
            $choices,
            'name',
            Project::appName(),
        );

        if ($selection['name'] === 'Create new project') {
            return $this->createProject($type);
        }

        return $selection;
    }

    protected function getProjectType(): string
    {
        $installed = collect($this->anyRequiredComposerPackages)->first(
            fn ($package) => Npm::packageIsInstalled($package)
        );

        if ($installed) {
            return 'javascript-' . collect(explode('/', $installed))->last();
        }

        // We're never going to hit this, but it's a sensible default
        return 'javascript';
    }
}
