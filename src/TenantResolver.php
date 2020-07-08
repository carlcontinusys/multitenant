<?php
namespace DanTheDJ\MultiTenant;

use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\EventDispatcher\EventDispatcher;
use DanTheDJ\MultiTenant\Events\TenantActivatedEvent;
use DanTheDJ\MultiTenant\Events\TenantResolvedEvent;
use DanTheDJ\MultiTenant\Events\TenantNotResolvedEvent;
use DanTheDJ\MultiTenant\Events\TenantNotResolvedException;
use DanTheDJ\MultiTenant\Contracts\TenantContract;
use Illuminate\Database\DatabaseManager;

class TenantResolver
{
    protected $app = null;
    protected $tenant = null;
    protected $db = null;
    protected $request = null;
    protected $activeTenant = null;
    protected $consoleDispatcher = false;
    protected $defaultConnection = null;
    protected $tenantConnection = null;

    public function __construct(Application $app, TenantContract $tenant, DatabaseManager $manager)
    {
        $this->app = $app;
        $this->tenant = $tenant;
        $this->db = $manager;
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
            $this->purgeConnection();
        }

        $this->app['db']->setDefaultConnection($connection);

        $this->purgeConnection();

    }

    protected function getConsoleDispatcher()
    {
        if (!$this->consoleDispatcher)
        {
            $this->consoleDispatcher = app(EventDispatcher::class);
        }

        return $this->consoleDispatcher;
    }

    public function purgeConnection()
    {

        $this->db->purge();

    }

}
