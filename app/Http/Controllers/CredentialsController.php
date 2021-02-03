<?php

namespace App\Http\Controllers;

use App\Enums\UserPermission;
use App\Exceptions\InvalidCredentialException;
use App\Http\Requests\StoreCredentialsRequest;
use App\Http\Requests\UpdateCredentialRequest;
use App\Models\Credential;
use App\Models\PublicAdministration;
use App\Models\Website;
use App\Traits\HasRoleAwareUrls;
use App\Traits\SendsResponse;
use App\Transformers\CredentialsTransformer;
use App\Transformers\WebsitesPermissionsTransformer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\DataTables;

class CredentialsController extends Controller
{
    use HasRoleAwareUrls;
    use SendsResponse;

    protected $clientService;

    public function __construct()
    {
        $this->clientService = app()->make('kong-client-service');
    }

    /**
     * Display the credential list.
     *
     * @param PublicAdministration $publicAdministration the public administration the websites belong to
     *
     * @return View the view
     */
    public function index(Request $request, PublicAdministration $publicAdministration)
    {
        $credentialsDatatable = [
            'columns' => [
                ['data' => 'client_name', 'name' => __('Nome della credenziale'), 'className' => 'text-wrap'],
                ['data' => 'consumer_id', 'name' => __('Consumer ID')],
                ['data' => 'added_at', 'name' => __('aggiunto il')],
                ['data' => 'icons', 'name' => '', 'orderable' => false],
                ['data' => 'buttons', 'name' => '', 'orderable' => false],
            ],
            'source' => $this->getRoleAwareUrl('api-credential.data.json', [], $publicAdministration),
            'caption' => __('elenco delle chiavi presenti su :app', ['app' => config('app.name')]),
            'columnsOrder' => [['added_at', 'asc'], ['client_name', 'asc']],
        ];

        $roleAwareUrls = $this->getRoleAwareUrlArray([
            'newCredentialUrl' => 'api-credential.create',
        ], [], $publicAdministration);

        return view('pages.credentials.index')
            ->with($roleAwareUrls)
            ->with($credentialsDatatable);
    }

    /**
     * Create a new Credential.
     *
     * @param Request $request the request
     * @param PublicAdministration $publicAdministration the credentials belong to
     *
     * @return View the view
     */
    public function create(Request $request, PublicAdministration $publicAdministration): View
    {
        $user = auth()->user();
        $currentPublicAdministration = $user->can(UserPermission::ACCESS_ADMIN_AREA)
            ? $publicAdministration
            : current_public_administration();

        $credentialsStoreUrl = $this->getRoleAwareUrl('api-credential.store', [], $currentPublicAdministration);

        $websitesPermissionsDatatableSource = $this->getRoleAwareUrl(
            'api-credential.websites.permissions.make',
            ['credential' => null],
            $currentPublicAdministration
        );

        $websitesPermissionsDatatable = $this->getDatatableWebsitesPermissionsParams($websitesPermissionsDatatableSource);

        return view('pages.credentials.add')->with(compact('credentialsStoreUrl'))->with($websitesPermissionsDatatable);
    }

    /**
     * Display the credential details.
     *
     * @param Request $request the request
     * @param Credential $credential the credential
     * @param PublicAdministration $publicAdministration the public administration the user belongs to
     *
     * @return View|Redirect the view or redirect error
     */
    public function show(Request $request, Credential $credential, PublicAdministration $publicAdministration)
    {
        $user = auth()->user();

        $currentPublicAdministration = $user->can(UserPermission::ACCESS_ADMIN_AREA)
            ? $publicAdministration
            : current_public_administration();

        if ($currentPublicAdministration->id === $credential->public_administration_id) {
            $client = $this->clientService->getClient($credential->consumer_id);

            $consumer = $this->clientService->getConsumer($credential->consumer_id);
            $customId = (array) json_decode($consumer['custom_id']);
            $conumerType = $customId['type'];

            $sitesIdArray = is_array($customId['siteId'])
                ? $customId['siteId']
                : [];

            $roleAwareUrls = $this->getRoleAwareUrlArray([
                'credentialEditUrl' => 'api-credential.edit',
            ], [
                'credential' => $credential,
            ], $currentPublicAdministration);

            $websitesPermissionsDatatableSource = $this->getRoleAwareUrl(
                'api-credential.websites.permissions',
                ['credential' => $credential, 'oldCredentialPermissions' => $sitesIdArray],
                $currentPublicAdministration
            );

            $websitesPermissionsDatatable = $this->getDatatableWebsitesPermissionsParams($websitesPermissionsDatatableSource, true);

            $credentialData = [
                'client' => $client,
                'type' => $conumerType,
            ];

            return view('pages.credentials.show')
                ->with(compact('credential'))
                ->with($credentialData)
                ->with($websitesPermissionsDatatable)
                ->with($roleAwareUrls);
        }

        return redirect()->home()->withNotification([
            'title' => __('Non è possibile visualizzare questa credenziale'),
            'message' => "La credenziale appartiene ad un'altra pubblica amministrazione",
            'status' => 'error',
            'icon' => 'it-close-circle',
        ]);
    }

    public function showJson(Credential $credential, PublicAdministration $publicAdministration)
    {
        $user = auth()->user();

        $currentPublicAdministration = $user->can(UserPermission::ACCESS_ADMIN_AREA)
            ? $publicAdministration
            : current_public_administration();

        if ($currentPublicAdministration->id === $credential->public_administration_id) {
            $credentialData = $this->clientService->getClient($credential->consumer_id);

            return response()->json([
                'credential' => $credentialData,
            ], 200);
        }

        return response()->json([
            'Error' => true,
            'Message' => "La credenziale appartiene ad un'altra pubblica amministrazione",
        ], 403);
    }

    /**
     * Edit credential view page.
     *
     * @param Request $request The request
     * @param Credential $credential The credential
     * @param PublicAdministration $publicAdministration the public administration the user belongs to
     *
     * @return View the view
     */
    public function edit(Request $request, Credential $credential, PublicAdministration $publicAdministration): View
    {
        $user = auth()->user();
        $currentPublicAdministration = $user->can(UserPermission::ACCESS_ADMIN_AREA)
            ? $publicAdministration
            : current_public_administration();

        $updateUrl = $this->getRoleAwareUrl('api-credential.update', [
            'credential' => $credential,
        ], $currentPublicAdministration);

        $consumer = $this->clientService->getConsumer($credential->consumer_id);
        $customId = json_decode($consumer['custom_id']);
        $sitesIdArray = is_array($customId->siteId)
            ? $customId->siteId
            : [];

        $websitesPermissionsDatatableSource = $this->getRoleAwareUrl(
            'api-credential.websites.permissions.make',
            [
                'credential' => $credential,
                'oldCredentialPermissions' => $sitesIdArray,
            ],
            $currentPublicAdministration
        );

        $credentialData = [
            'type' => $customId->type,
        ];

        $websitesPermissionsDatatable = $this->getDatatableWebsitesPermissionsParams($websitesPermissionsDatatableSource);

        return view('pages.credentials.edit')
            ->with(compact('credential', 'updateUrl'))
            ->with($websitesPermissionsDatatable)
            ->with($credentialData);
    }

    /**
     * Update the credentials.
     *
     * @param UpdateCredentialRequest $request The request
     * @param Credential $credential The credential
     *
     * @return RedirectResponse
     */
    public function update(UpdateCredentialRequest $request, Credential $credential): RedirectResponse
    {
        $validatedData = $request->validated();

        $permissions = [];
        if (array_key_exists('permissions', $validatedData)) {
            foreach ($validatedData['permissions'] as $credentialId => $permission) {
                array_push($permissions, ['id' => $credentialId, 'permission' => implode('', $permission)]);
            }
        }

        $credential->fill([
            'client_name' => $validatedData['credential_name'],
        ]);
        $credential->save();

        $clientJSON = $this->clientService->updateClient(
            $credential->consumer_id,
            [
                'username' => $validatedData['credential_name'],
                'custom_id' => json_encode(['type' => $validatedData['type'], 'siteId' => $permissions]),
            ]
        );

        return redirect()->route('api-credential.index')->withModal([
            'title' => __('modifica credenziale'),
            'icon' => 'it-check-circle',
            'message' => __('La modifica della credenziale :credential è andata a buon fine.', [
                'credential' => '<strong>' . $validatedData['credential_name'] . '</strong>',
            ]),
        ]);
    }

    /**
     * Deletes the credential.
     *
     * @param Credential $credential The credential
     *
     * @return JsonResponse|RedirectResponse the response in json or http redirect format
     */
    public function delete(Credential $credential)
    {
        try {
            $this->clientService->deleteConsumer($credential->consumer_id);
            $credential->delete();

            return $this->credentialResponse($credential);
        } catch (InvalidCredentialException $exception) {
            report($exception);
            $code = $exception->getCode();
            $message = 'Invalid Credential';
            $httpStatusCode = 400;
        }

        return $this->errorResponse($message, $code, $httpStatusCode);
    }

    /**
     * Get the Credentials data.
     *
     * @param PublicAdministration $publicAdministration the Public Administration to filter credentials or null to use current one
     *
     * @throws \Exception if unable to initialize the datatable
     *
     * @return mixed the response in JSON format
     */
    public function dataJson(PublicAdministration $publicAdministration)
    {
        $user = auth()->user();
        $currentPublicAdministration = $user->can(UserPermission::ACCESS_ADMIN_AREA)
            ? $publicAdministration
            : current_public_administration();

        $data = $currentPublicAdministration->credentials()->get();

        return DataTables::of($data)
            ->setTransformer(new CredentialsTransformer())
            ->make(true);
    }

    public function store(StoreCredentialsRequest $request, PublicAdministration $publicAdministration)
    {
        $validatedData = $request->validated();
        $permissions = [];

        if (array_key_exists("permissions", $validatedData)) {
            foreach ($validatedData['permissions'] as $credential => $permission) {
                array_push($permissions, ['id' => $credential, 'permission' => implode('', $permission)]);
            }
        }

        $user = auth()->user();

        $currentPublicAdministration = $user->can(UserPermission::ACCESS_ADMIN_AREA)
            ? $publicAdministration
            : current_public_administration();

        $clientJSON = $this->clientService
            ->makeConsumer(
                $validatedData['credential_name'],
                json_encode(['type' => $validatedData['type'], 'siteId' => $permissions])
            );

        $client = Credential::create([
            'client_name' => $validatedData['credential_name'],
            'public_administration_id' => $currentPublicAdministration->id,
            'consumer_id' => $clientJSON['consumer']['id'],
        ]);

        return redirect()->route('api-credential.index')->withModal([
            'title' => __('La credenziale è stata inserita!'),
            'icon' => 'it-check-circle',
            'message' => __('Adesso puoi utilizzare la tua nuova credenziale e usare le API con il flusso "Client credentials" OAuth2.'),
        ]);
    }

    /**
     * Show the oauth credential permissions on websites.
     *
     * @param Website $websites websites associated with the credential
     *
     * @throws \Exception if unable to initialize the datatable
     *
     * @return mixed the response in JSON format
     */
    public function showDataCredentialsWebsitesPermissionsJson(Credential $credential)
    {
        $consumer = $this->clientService->getConsumer($credential->consumer_id);
        $customId = json_decode($consumer['custom_id']); //explode(',', $consumer['custom_id']);
        $sitesIdArray = is_array($customId->siteId)
            ? array_map(function ($elem) {
                return $elem->id;
            }, $customId->siteId)
            : [];

        $websites = Website::whereIn('id', $sitesIdArray)->get();

        return DataTables::of($websites)
            ->setTransformer(new WebsitesPermissionsTransformer())
            ->make(true);
    }

    /**
     * Show the oauth credential permissions on websites.
     *
     * @param Website $websites websites associated with the credential
     *
     * @throws \Exception if unable to initialize the datatable
     *
     * @return mixed the response in JSON format
     */
    public function makeCredentialsWebsitesPermissionsJson(?Credential $credential)
    {
        $publicAdministration = current_public_administration();

        $websites = $publicAdministration->websites->all();

        return DataTables::of($websites)
            ->setTransformer(new WebsitesPermissionsTransformer())
            ->make(true);
    }

    /**
     * Get the datatable parameters for websites permission with specified source.
     *
     * @param string $source the source paramater for the websites permission datatable
     * @param bool $readonly wether the datatable is readonly
     *
     * @return array the datatable parameters
     */
    public function getDatatableWebsitesPermissionsParams(string $source, bool $readonly = false): array
    {
        return [
            'datatableOptions' => [
                'searching' => [
                    'label' => __('cerca tra i siti web'),
                ],
                'columnFilters' => [
                    'type' => [
                        'filterLabel' => __('tipologia'),
                    ],
                    'status' => [
                        'filterLabel' => __('stato'),
                    ],
                ],
            ],
            'columns' => [
                ['data' => 'website_name', 'name' => __('nome del sito'), 'className' => 'text-wrap'],
                ['data' => 'type', 'name' => __('tipologia')],
                ['data' => 'status', 'name' => __('stato')],
                [
                    'data' => ($readonly ? 'icons' : 'toggles'),
                    'name' => __('permessi della credenziale'),
                    'orderable' => false,
                    'searchable' => false,
                ],
            ],
            'source' => $source . '?&editCredentialPermissions' . ($readonly ? '&readOnly' : ''),
            'caption' => __('elenco dei siti web presenti su :app', ['app' => config('app.name')]),
            'columnsOrder' => [['website_name', 'asc']],
        ];
    }
}
