<?php
namespace DanTheDJ\MultiTenant;

use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Symfony\Component\Console\ConsoleEvents;
use Illuminate\Console\Events\ArtisanStarting;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputOption;
use DanTheDJ\MultiTenant\Contracts\TenantContract;
use DanTheDJ\MultiTenant\Events\TenantResolvedEvent;
use DanTheDJ\MultiTenant\Events\TenantActivatedEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use DanTheDJ\MultiTenant\Events\TenantNotResolvedEvent;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use DanTheDJ\MultiTenant\Events\TenantNotResolvedException;

class TenantResolver
{
    protected $app = null;
    protected $tenant = null;
    protected $request = null;
    protected $activeTenant = null;
    protected $consoleDispatcher = false;
    protected $defaultConnection = null;
    protected $tenantConnection = null;

    public function __construct(Application $app, TenantContract $tenant)
    {
        $this->app = $app;
        $this->tenant = $tenant;
        $this->defaultConnection = $this->app['db']->getDefaultConnection();
        $this->tenantConnection = 'envtenant';
        config()->set('database.connections.' . $this->tenantConnection, config('database.connections.' . $this->defaultConnection));
    }

    public function setActiveTenant(TenantContract $activeTenant)
    {
        $this->activeTenant = $activeTenant;
        $this->setDefaultConnection($activeTenant);

        event(new TenantActivatedEvent($activeTenant));
    }

    public function getActiveTenant()
    {
        return $this->activeTenant;
    }

    public function getAllTenants()
    {
        return $this->tenant->get();
    }

    public function mapAllTenants($callback)
    {
        $tenants = $this->getAllTenants();

        foreach($tenants as $tenant)
        {
            $this->setActiveTenant($tenant);

            $callback($tenant);
        }
    }

    public function reconnectDefaultConnection()
    {
        $this->setDefaultConnection($this->tenantConnection);
    }

    public function reconnectTenantConnection()
    {
        $this->setDefaultConnection($this->getActiveTenant());
    }

    public function resolveTenant()
    {
        $this->registerTenantConsoleArgument();
        $this->registerConsoleStartEvent();
        $this->registerConsoleTerminateEvent();
        $this->resolveRequest();
    }

    public function isResolved()
    {
        return ! is_null($this->getActiveTenant());
    }

    protected function resolveRequest()
    {
        if ($this->app->runningInConsole())
        {
            $domain = (new ArgvInput())->getParameterOption('--tenant', null);

            try
            {
                if(is_null($domain))
                {
                  throw new \Exception();
                }
                $model = $this->tenant;
                $tenant = $model
                    ->where('subdomain', '=', $domain)
                    ->orWhere('alias_domain', '=', $domain)
                    ->first();
            }
            catch (\Exception $e)
            {
                $tenant = null;
                echo $e->getMessage();
            }
        }
        else
        {
            $this->request = $this->app->make(Request::class);
            $domain = $this->request->getHost();
            $subdomain = explode('.', $domain)[0];
            $id = $this->request->segment(1);

            $model = $this->tenant;
            $tenant = $model
                ->where(function($query) use ($subdomain, $domain)
                {
                    $query->where('subdomain', '=', $subdomain);
                    $query->orWhere('alias_domain', '=', $domain);
                })
                ->first();
        }

        if (
            empty($tenant->connection) ||
            ( ! empty($tenant->connection) && $tenant->connection === 'pending')
        ) $tenant = null;

        if ($tenant instanceof TenantContract)
        {
            $this->setActiveTenant($tenant);

            event(new TenantResolvedEvent($tenant));

            return;
        }

        event(new TenantNotResolvedEvent($domain));

        if ( ! $this->app->runningInConsole())
        {
            throw new TenantNotResolvedException($domain);
        }

        return;
    }

    public function resolveBySubdomain($subDomain)
    {

        $model = $this->tenant;

        $tenant = $model
            ->where(function($query) use ($subDomain)
            {
                $query->where('subdomain', '=', $subDomain);
            })
            ->first();


        if (
            empty($tenant->connection) ||
            ( ! empty($tenant->connection) && $tenant->connection === 'pending')
        ) $tenant = null;

        if ($tenant instanceof TenantContract)
        {
            $this->setActiveTenant($tenant);

            event(new TenantResolvedEvent($tenant));

            return;
        }

        event(new TenantNotResolvedEvent($subDomain));

        if ( ! $this->app->runningInConsole())
        {
            throw new TenantNotResolvedException($subDomain);
        }

        return;
    }

    protected function setDefaultConnection($activeTenant)
    {
        $hasConnection = ! empty($activeTenant->connection);
        $connection = $hasConnection ? $activeTenant->connection : $this->tenantConnection;
        $databaseName = ($hasConnection && ! empty($activeTenant->subdomain)) ? $activeTenant->subdomain : '';
        $databasePrefix = ($hasConnection && ! empty($activeTenant->subdomain)) ? config()->get('database.connections.' . $connection . '.database_prefix') : '';

        if ($hasConnection && empty($activeTenant->subdomain))
        {
            throw new TenantDatabaseNameEmptyException();
        }

        config()->set('database.default', $connection);
        config()->set('database.connections.' . $connection . '.database', $databasePrefix . $databaseName);

        if ($hasConnection)
        {
            config()->set('tenant', $activeTenant->toArray());
            $this->app['db']->purge($this->defaultConnection);
        }

        $this->app['db']->setDefaultConnection($connection);
    }

    protected function getConsoleDispatcher()
    {
        if (!$this->consoleDispatcher)
        {
            $this->consoleDispatcher = app(EventDispatcher::class);
        }

        return $this->consoleDispatcher;
    }

    protected function registerTenantConsoleArgument()
    {
        $this->app['events']->listen(ArtisanStarting::class, function($event)
        {
            $definition = $event->artisan->getDefinition();

            $definition->addOption(
                new InputOption('--tenant', null, InputOption::VALUE_OPTIONAL, 'The tenant subdomain or alias domain the command should be run for. Use * or all for every tenant.')
            );

            $event->artisan->setDefinition($definition);
            $event->artisan->setDispatcher($this->getConsoleDispatcher());
        });
    }

    protected function registerConsoleStartEvent()
    {
        $this->getConsoleDispatcher()->addListener(ConsoleEvents::COMMAND, function(ConsoleCommandEvent $event)
        {
            $tenant = $event->getInput()->getParameterOption('--tenant', null);

            if ( ! is_null($tenant))
            {
                if ($tenant == '*' || $tenant == 'all')
                {
                    $event->disableCommand();
                }
                else
                {
                    if ($this->isResolved())
                    {
                        $event->getOutput()->writeln('<info>Running command for ' . $this->getActiveTenant()->name . '</info>');
                    }
                    else
                    {
                        $event->getOutput()->writeln('<error>Failed to resolve tenant</error>');
                        $event->disableCommand();
                    }
                }
            }
        });
    }

    protected function registerConsoleTerminateEvent()
    {
        $this->getConsoleDispatcher()->addListener(ConsoleEvents::TERMINATE, function(ConsoleTerminateEvent $event)
        {
            $tenant = $event->getInput()->getParameterOption('--tenant', null);

            if( ! is_null($tenant))
            {
                if ($tenant == '*' || $tenant == 'all')
                {
                    $command = $event->getCommand();
                    $input = $event->getInput();
                    $output = $event->getOutput();
                    $exitCode = $event->getExitCode();

                    $tenants = $this->getAllTenants();

                    foreach($tenants as $tenant)
                    {
                        $this->setActiveTenant($tenant);

                        $event->getOutput()->writeln('<info>Running command for ' . $this->getActiveTenant()->name . '</info>');

                        try
                        {
                            $exitCode = $command->run($input, $output);
                        }
                        catch (\Exception $e)
                        {
                            $event = new ConsoleErrorEvent($input, $output, $e, $command);

                            $this->getConsoleDispatcher()->dispatch(ConsoleEvents::ERROR, $event);

                            $e = $event->getError();

                            throw $e;
                        }
                    }

                    $event->setExitCode($exitCode);
                }
            }
        });
    }
}
