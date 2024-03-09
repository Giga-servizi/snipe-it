<?php

namespace App\Http\Controllers\Api;

use App\Events\CheckoutableCheckedIn;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssetCheckoutRequest;
use App\Http\Transformers\AssetsTransformer;
use App\Http\Transformers\LicensesTransformer;
use App\Http\Transformers\SelectlistTransformer;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\License;
use App\Models\Location;
use App\Models\Setting;
use App\Models\User;
use \Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use App\Http\Requests\ImageUploadRequest;
use Illuminate\Support\Facades\Log;
use Input;
use Paginator;
use Slack;
use Str;
use TCPDF;
use Validator;
use Route;


/**
 * This class controls all actions related to assets for
 * the Snipe-IT Asset Management application.
 *
 * @version    v1.0
 * @author [A. Gianotto] [<snipe@snipe.net>]
 */
class AssetsController extends Controller
{
    /**
     * Returns JSON listing of all assets
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @since [v4.0]
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, $audit = null) 
    {

        $filter_non_deprecable_assets = false;

        /**
         * This looks MAD janky (and it is), but the AssetsController@index does a LOT of heavy lifting throughout the 
         * app. This bit here just makes sure that someone without permission to view assets doesn't 
         * end up with priv escalations because they asked for a different endpoint. 
         * 
         * Since we never gave the specification for which transformer to use before, it should default 
         * gracefully to just use the AssetTransformer by default, which shouldn't break anything. 
         * 
         * It was either this mess, or repeating ALL of the searching and sorting and filtering code, 
         * which would have been far worse of a mess. *sad face*  - snipe (Sept 1, 2021)
         */
        if (Route::currentRouteName()=='api.depreciation-report.index') {
            $filter_non_deprecable_assets = true;
            $transformer = 'App\Http\Transformers\DepreciationReportTransformer';
            $this->authorize('reports.view');
        } else {
            $transformer = 'App\Http\Transformers\AssetsTransformer';
            $this->authorize('index', Asset::class);          
        }
        
       
        $settings = Setting::getSettings();

        $allowed_columns = [
            'id',
            'name',
            'asset_tag',
            'serial',
            'model_number',
            'last_checkout',
            'notes',
            'expected_checkin',
            'order_number',
            'image',
            'assigned_to',
            'created_at',
            'updated_at',
            'purchase_date',
            'purchase_cost',
            'last_audit_date',
            'next_audit_date',
            'warranty_months',
            'checkout_counter',
            'checkin_counter',
            'requests_counter',
            'byod',
            'asset_eol_date',
        ];

        $filter = [];

        if ($request->filled('filter')) {
            $filter = json_decode($request->input('filter'), true);
        }

        $all_custom_fields = CustomField::all(); //used as a 'cache' of custom fields throughout this page load
        foreach ($all_custom_fields as $field) {
            $allowed_columns[] = $field->db_column_name();
        }

        $assets = Asset::select('assets.*')
            ->with('location', 'assetstatus', 'company', 'defaultLoc','assignedTo',
                'model.category', 'model.manufacturer', 'model.fieldset','supplier'); //it might be tempting to add 'assetlog' here, but don't. It blows up update-heavy users.


        if ($filter_non_deprecable_assets) {
            $non_deprecable_models = AssetModel::select('id')->whereNotNull('depreciation_id')->get();
            $assets->InModelList($non_deprecable_models->toArray());
        }



        // These are used by the API to query against specific ID numbers.
        // They are also used by the individual searches on detail pages like
        // locations, etc.

        // Search custom fields by column name
        foreach ($all_custom_fields as $field) {
            if ($request->filled($field->db_column_name()) && $field->db_column_name()) {
                $assets->where($field->db_column_name(), '=', $request->input($field->db_column_name()));
            }
        }

        if ((! is_null($filter)) && (count($filter)) > 0) {
            $assets->ByFilter($filter);
        } elseif ($request->filled('search')) {
            $assets->TextSearch($request->input('search'));
        }

        // This is used by the audit reporting routes
        if (Gate::allows('audit', Asset::class)) {
            switch ($audit) {
                case 'due':
                    $assets->DueOrOverdueForAudit($settings);
                    break;
                case 'overdue':
                    $assets->overdueForAudit($settings);
                    break;
            }
        }


        // This is used by the sidenav, mostly

        // We switched from using query scopes here because of a Laravel bug
        // related to fulltext searches on complex queries.
        // I am sad. :(
        switch ($request->input('status')) {
            case 'Deleted':
                $assets->onlyTrashed();
                break;
            case 'Pending':
                $assets->join('status_labels AS status_alias', function ($join) {
                    $join->on('status_alias.id', '=', 'assets.status_id')
                        ->where('status_alias.deployable', '=', 0)
                        ->where('status_alias.pending', '=', 1)
                        ->where('status_alias.archived', '=', 0);
                });
                break;
            case 'RTD':
                $assets->whereNull('assets.assigned_to')
                    ->join('status_labels AS status_alias', function ($join) {
                        $join->on('status_alias.id', '=', 'assets.status_id')
                            ->where('status_alias.deployable', '=', 1)
                            ->where('status_alias.pending', '=', 0)
                            ->where('status_alias.archived', '=', 0);
                    });
                break;
            case 'Undeployable':
                $assets->Undeployable();
                break;
            case 'Archived':
                $assets->join('status_labels AS status_alias', function ($join) {
                    $join->on('status_alias.id', '=', 'assets.status_id')
                        ->where('status_alias.deployable', '=', 0)
                        ->where('status_alias.pending', '=', 0)
                        ->where('status_alias.archived', '=', 1);
                });
                break;
            case 'Requestable':
                $assets->where('assets.requestable', '=', 1)
                    ->join('status_labels AS status_alias', function ($join) {
                        $join->on('status_alias.id', '=', 'assets.status_id')
                            ->where('status_alias.deployable', '=', 1)
                            ->where('status_alias.pending', '=', 0)
                            ->where('status_alias.archived', '=', 0);
                    });

                break;
            case 'Deployed':
                // more sad, horrible workarounds for laravel bugs when doing full text searches
                $assets->whereNotNull('assets.assigned_to');
                break;
            case 'byod':
                // This is kind of redundant, since we already check for byod=1 above, but this keeps the
                // sidebar nav links a little less chaotic
                $assets->where('assets.byod', '=', '1');
                break;
            default:

                if ((! $request->filled('status_id')) && ($settings->show_archived_in_list != '1')) {
                    // terrible workaround for complex-query Laravel bug in fulltext
                    $assets->join('status_labels AS status_alias', function ($join) {
                        $join->on('status_alias.id', '=', 'assets.status_id')
                            ->where('status_alias.archived', '=', 0);
                    });

                    // If there is a status ID, don't take show_archived_in_list into consideration
                } else {
                    $assets->join('status_labels AS status_alias', function ($join) {
                        $join->on('status_alias.id', '=', 'assets.status_id');
                    });
                }

        }


        // Leave these under the TextSearch scope, else the fuzziness will override the specific ID (status ID, etc) requested
        if ($request->filled('status_id')) {
            $assets->where('assets.status_id', '=', $request->input('status_id'));
        }

        if ($request->filled('asset_tag')) {
            $assets->where('assets.asset_tag', '=', $request->input('asset_tag'));
        }

        if ($request->filled('serial')) {
            $assets->where('assets.serial', '=', $request->input('serial'));
        }

        if ($request->input('requestable') == 'true') {
            $assets->where('assets.requestable', '=', '1');
        }

        if ($request->filled('model_id')) {
            $assets->InModelList([$request->input('model_id')]);
        }

        if ($request->filled('category_id')) {
            $assets->InCategory($request->input('category_id'));
        }

        if ($request->filled('location_id')) {
            $assets->where('assets.location_id', '=', $request->input('location_id'));
        }

        if ($request->filled('rtd_location_id')) {
            $assets->where('assets.rtd_location_id', '=', $request->input('rtd_location_id'));
        }

        if ($request->filled('supplier_id')) {
            $assets->where('assets.supplier_id', '=', $request->input('supplier_id'));
        }

        if ($request->filled('asset_eol_date')) {
            $assets->where('assets.asset_eol_date', '=', $request->input('asset_eol_date'));
        }

        if (($request->filled('assigned_to')) && ($request->filled('assigned_type'))) {
            $assets->where('assets.assigned_to', '=', $request->input('assigned_to'))
                ->where('assets.assigned_type', '=', $request->input('assigned_type'));
        }

        if ($request->filled('company_id')) {
            $assets->where('assets.company_id', '=', $request->input('company_id'));
        }

        if ($request->filled('manufacturer_id')) {
            $assets->ByManufacturer($request->input('manufacturer_id'));
        }

        if ($request->filled('depreciation_id')) {
            $assets->ByDepreciationId($request->input('depreciation_id'));
        }

        if ($request->filled('byod')) {
            $assets->where('assets.byod', '=', $request->input('byod'));
        }

        if ($request->filled('order_number')) {
            $assets->where('assets.order_number', '=', strval($request->get('order_number')));
        }

        // This is kinda gross, but we need to do this because the Bootstrap Tables
        // API passes custom field ordering as custom_fields.fieldname, and we have to strip
        // that out to let the default sorter below order them correctly on the assets table.
        $sort_override = str_replace('custom_fields.', '', $request->input('sort'));

        // This handles all of the pivot sorting (versus the assets.* fields
        // in the allowed_columns array)
        $column_sort = in_array($sort_override, $allowed_columns) ? $sort_override : 'assets.created_at';

        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';
        
        switch ($sort_override) {
            case 'model':
                $assets->OrderModels($order);
                break;
            case 'model_number':
                $assets->OrderModelNumber($order);
                break;
            case 'category':
                $assets->OrderCategory($order);
                break;
            case 'manufacturer':
                $assets->OrderManufacturer($order);
                break;
            case 'company':
                $assets->OrderCompany($order);
                break;
            case 'location':
                $assets->OrderLocation($order);
            case 'rtd_location':
                $assets->OrderRtdLocation($order);
                break;
            case 'status_label':
                $assets->OrderStatus($order);
                break;
            case 'supplier':
                $assets->OrderSupplier($order);
                break;
            case 'assigned_to':
                $assets->OrderAssigned($order);
                break;
            default:
                $assets->orderBy($column_sort, $order);
                break;
        }


        // Make sure the offset and limit are actually integers and do not exceed system limits
        $offset = ($request->input('offset') > $assets->count()) ? $assets->count() : app('api_offset_value');
        $limit = app('api_limit_value');

        $total = $assets->count();
        $assets = $assets->skip($offset)->take($limit)->get();
        

        /**
         * Include additional associated relationships
         */  
        if ($request->input('components')) {
            $assets->loadMissing(['components' => function ($query) {
                $query->orderBy('created_at', 'desc');
            }]);
        }



        /**
         * Here we're just determining which Transformer (via $transformer) to use based on the 
         * variables we set earlier on in this method - we default to AssetsTransformer.
         */
        return (new $transformer)->transformAssets($assets, $total, $request);
    }


    /**
     * Returns JSON with information about an asset (by tag) for detail view.
     *
     * @param string $tag
     * @since [v4.2.1]
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @return \Illuminate\Http\JsonResponse
     */
    public function showByTag(Request $request, $tag)
    {
        $this->authorize('index', Asset::class);
        $assets = Asset::where('asset_tag', $tag)->with('assetstatus')->with('assignedTo');

        // Check if they've passed ?deleted=true
        if ($request->input('deleted', 'false') == 'true') {
            $assets = $assets->withTrashed();
        }

        if (($assets = $assets->get()) && ($assets->count()) > 0) {

            // If there is exactly one result and the deleted parameter is not passed, we should pull the first (and only)
            // asset from the returned collection, since transformAsset() expects an Asset object, NOT a collection
            if (($assets->count() == 1) && ($request->input('deleted') != 'true')) {
                return (new AssetsTransformer)->transformAsset($assets->first());

                // If there is more than one result OR if the endpoint is requesting deleted items (even if there is only one
                // match, return the normal collection transformed.
            } else {
                return (new AssetsTransformer)->transformAssets($assets, $assets->count());
            }

        }

        // If there are 0 results, return the "no such asset" response
        return response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/hardware/message.does_not_exist')), 200);

    }

    /**
     * Returns JSON with information about an asset (by serial) for detail view.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param string $serial
     * @since [v4.2.1]
     * @return \Illuminate\Http\JsonResponse
     */
    public function showBySerial(Request $request, $serial)
    {
        $this->authorize('index', Asset::class);
        $assets = Asset::where('serial', $serial)->with('assetstatus')->with('assignedTo');

        // Check if they've passed ?deleted=true
        if ($request->input('deleted', 'false') == 'true') {
            $assets = $assets->withTrashed();
        }
        
        if (($assets = $assets->get()) && ($assets->count()) > 0) {
             return (new AssetsTransformer)->transformAssets($assets, $assets->count());
        }

        // If there are 0 results, return the "no such asset" response
        return response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/hardware/message.does_not_exist')), 200);

    }

    /**
     * Returns JSON with information about an asset for detail view.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @since [v4.0]
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $id)
    {
        if ($asset = Asset::with('assetstatus')->with('assignedTo')->withTrashed()
            ->withCount('checkins as checkins_count', 'checkouts as checkouts_count', 'userRequests as user_requests_count')->findOrFail($id)) {
            $this->authorize('view', $asset);

            return (new AssetsTransformer)->transformAsset($asset, $request->input('components') );
        }


    }

    public function licenses(Request $request, $id)
    {
        $this->authorize('view', Asset::class);
        $this->authorize('view', License::class);
        $asset = Asset::where('id', $id)->withTrashed()->firstorfail();
        $licenses = $asset->licenses()->get();

        return (new LicensesTransformer())->transformLicenses($licenses, $licenses->count());
     }


    /**
     * Gets a paginated collection for the select2 menus
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0.16]
     * @see \App\Http\Transformers\SelectlistTransformer
     * @return \Illuminate\Http\JsonResponse
     */
    public function selectlist(Request $request)
    {

        $assets = Asset::select([
            'assets.id',
            'assets.name',
            'assets.asset_tag',
            'assets.model_id',
            'assets.assigned_to',
            'assets.assigned_type',
            'assets.status_id',
            ])->with('model', 'assetstatus', 'assignedTo')->NotArchived();

        if ($request->filled('assetStatusType') && $request->input('assetStatusType') === 'RTD') {
            $assets = $assets->RTD();
        }

        if ($request->filled('search')) {
            $assets = $assets->AssignedSearch($request->input('search'));
        }


        $assets = $assets->paginate(50);

        // Loop through and set some custom properties for the transformer to use.
        // This lets us have more flexibility in special cases like assets, where
        // they may not have a ->name value but we want to display something anyway
        foreach ($assets as $asset) {


            $asset->use_text = $asset->present()->fullName;

            if (($asset->checkedOutToUser()) && ($asset->assigned)) {
                $asset->use_text .= ' → '.$asset->assigned->getFullNameAttribute();
            }


            if ($asset->assetstatus->getStatuslabelType() == 'pending') {
                $asset->use_text .= '('.$asset->assetstatus->getStatuslabelType().')';
            }

            $asset->use_image = ($asset->getImageUrl()) ? $asset->getImageUrl() : null;
        }

        return (new SelectlistTransformer)->transformSelectlist($assets);
    }


    /**
     * Accepts a POST request to create a new asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param \App\Http\Requests\ImageUploadRequest $request
     * @since [v4.0]
     */
    public function store(StoreAssetRequest $request): JsonResponse
    {
        $asset = new Asset();
        $asset->model()->associate(AssetModel::find((int) $request->get('model_id')));

        $asset->fill($request->validated());
        $asset->user_id    = Auth::id();

        /**
        * this is here just legacy reasons. Api\AssetController
        * used image_source  once to allow encoded image uploads.
        */
        if ($request->has('image_source')) {
            $request->offsetSet('image', $request->offsetGet('image_source'));
        }     

        $asset = $request->handleImages($asset);

        // Update custom fields in the database.
        $model = AssetModel::find($request->input('model_id'));

        // Check that it's an object and not a collection
        // (Sometimes people send arrays here and they shouldn't
        if (($model) && ($model instanceof AssetModel) && ($model->fieldset)) {
            foreach ($model->fieldset->fields as $field) {

                // Set the field value based on what was sent in the request
                $field_val = $request->input($field->db_column, null);

                // If input value is null, use custom field's default value
                if ($field_val == null) {
                    Log::debug('Field value for '.$field->db_column.' is null');
                    $field_val = $field->defaultValue($request->get('model_id'));
                    Log::debug('Use the default fieldset value of '.$field->defaultValue($request->get('model_id')));
                }

                // if the field is set to encrypted, make sure we encrypt the value
                if ($field->field_encrypted == '1') {
                    Log::debug('This model field is encrypted in this fieldset.');

                    if (Gate::allows('admin')) {

                        // If input value is null, use custom field's default value
                        if (($field_val == null) && ($request->has('model_id') != '')) {
                            $field_val = Crypt::encrypt($field->defaultValue($request->get('model_id')));
                        } else {
                            $field_val = Crypt::encrypt($request->input($field->db_column));
                        }
                    }
                }


                $asset->{$field->db_column} = $field_val;
            }
        }

        if ($asset->save()) {
            if ($request->get('assigned_user')) {
                $target = User::find(request('assigned_user'));
            } elseif ($request->get('assigned_asset')) {
                $target = Asset::find(request('assigned_asset'));
            } elseif ($request->get('assigned_location')) {
                $target = Location::find(request('assigned_location'));
            }
            if (isset($target)) {
                $asset->checkOut($target, Auth::user(), date('Y-m-d H:i:s'), '', 'Checked out on asset creation', e($request->get('name')));
            }

            if ($asset->image) {
                $asset->image = $asset->getImageUrl();
            }

            return response()->json(Helper::formatStandardApiResponse('success', $asset, trans('admin/hardware/message.create.success')));
        }

        return response()->json(Helper::formatStandardApiResponse('error', null, $asset->getErrors()), 200);
    }


    /**
     * Accepts a POST request to update an asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateAssetRequest $request, Asset $asset)
    {
        $asset->fill($request->validated());

        // TODO: how much of this can go in the validator?
        // this is _always_ filled now, see UpdateAssetRequest
        // i'm leaving this here for now, but when would we ever want model_id to be `null`??
        ($request->validated()['model_id']) ?
            $asset->model()->associate(AssetModel::find($request->validated()['model_id'])) : null;
        ($request->filled('rtd_location_id')) ?
            $asset->location_id = $request->get('rtd_location_id') : '';
        ($request->filled('company_id')) ?
            $asset->company_id = Company::getIdForCurrentUser($request->get('company_id')) : '';

        ($request->filled('rtd_location_id')) ?
            $asset->location_id = $request->get('rtd_location_id') : null;

        /**
         * this is here just legacy reasons. Api\AssetController
         * used image_source  once to allow encoded image uploads.
         */
        if ($request->has('image_source')) {
            $request->offsetSet('image', $request->offsetGet('image_source'));
        }

        $asset = $request->handleImages($asset);
        $model = AssetModel::find($asset->model_id);

        // Update custom fields
        if (($model) && (isset($model->fieldset))) {
            foreach ($model->fieldset->fields as $field) {
                if ($request->has($field->db_column)) {
                    if ($field->field_encrypted == '1') {
                        if (Gate::allows('admin')) {
                            $asset->{$field->db_column} = \Crypt::encrypt($request->input($field->db_column));
                        }
                    } else {
                        $asset->{$field->db_column} = $request->input($field->db_column);
                    }
                }
            }
        }


        if ($asset->save()) {
            if (($request->filled('assigned_user')) && ($target = User::find($request->get('assigned_user')))) {
                $location = $target->location_id;
            } elseif (($request->filled('assigned_asset')) && ($target = Asset::find($request->get('assigned_asset')))) {
                $location = $target->location_id;

                Asset::where('assigned_type', \App\Models\Asset::class)->where('assigned_to', $id)
                    ->update(['location_id' => $target->location_id]);
            } elseif (($request->filled('assigned_location')) && ($target = Location::find($request->get('assigned_location')))) {
                $location = $target->id;
            }

            if (isset($target)) {
                $asset->checkOut($target, Auth::user(), date('Y-m-d H:i:s'), '', 'Checked out on asset update', e($request->get('name')), $location);
            }

            if ($asset->image) {
                $asset->image = $asset->getImageUrl();
            }

            return response()->json(Helper::formatStandardApiResponse('success', $asset, trans('admin/hardware/message.update.success')));
        }

        return response()->json(Helper::formatStandardApiResponse('error', null, $asset->getErrors()), 200);

        // TODO: confirm that everything expects a _200_ model not found exception
    }


    /**
     * Delete a given asset (mark as deleted).
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @since [v4.0]
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $this->authorize('delete', Asset::class);

        if ($asset = Asset::find($id)) {
            $this->authorize('delete', $asset);

            DB::table('assets')
                ->where('id', $asset->id)
                ->update(['assigned_to' => null]);

            $asset->delete();

            return response()->json(Helper::formatStandardApiResponse('success', null, trans('admin/hardware/message.delete.success')));
        }

        return response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/hardware/message.does_not_exist')), 200);
    }

    

    /**
     * Restore a soft-deleted asset.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @since [v5.1.18]
     * @return \Illuminate\Http\JsonResponse
     */
    public function restore(Request $request, $assetId = null)
    {

        if ($asset = Asset::withTrashed()->find($assetId)) {
            $this->authorize('delete', $asset);

            if ($asset->deleted_at == '') {
                return response()->json(Helper::formatStandardApiResponse('error', trans('general.not_deleted', ['item_type' => trans('general.asset')])), 200);
            }

            if ($asset->restore()) {
                return response()->json(Helper::formatStandardApiResponse('success', trans('admin/hardware/message.restore.success')), 200);
            }

            // Check validation to make sure we're not restoring an asset with the same asset tag (or unique attribute) as an existing asset
            return response()->json(Helper::formatStandardApiResponse('error', trans('general.could_not_restore', ['item_type' => trans('general.asset'), 'error' => $asset->getErrors()->first()])), 200);
        }

        return response()->json(Helper::formatStandardApiResponse('error', null, trans('admin/hardware/message.does_not_exist')), 200);

    }

    /**
     * Checkout an asset by its tag.
     *
     * @author [N. Butler]
     * @param string $tag
     * @since [v6.0.5]
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkoutByTag(AssetCheckoutRequest $request, $tag)
    {
        if ($asset = Asset::where('asset_tag', $tag)->first()) {
            return $this->checkout($request, $asset->id);
        }
        return response()->json(Helper::formatStandardApiResponse('error', null, 'Asset not found'), 200);
    }

    /**
     * Checkout an asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @since [v4.0]
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkout(AssetCheckoutRequest $request, $asset_id)
    {
        $this->authorize('checkout', Asset::class);
        $asset = Asset::findOrFail($asset_id);

        if (! $asset->availableForCheckout()) {
            return response()->json(Helper::formatStandardApiResponse('error', ['asset'=> e($asset->asset_tag)], trans('admin/hardware/message.checkout.not_available')));
        }

        $this->authorize('checkout', $asset);

        $error_payload = [];
        $error_payload['asset'] = [
            'id' => $asset->id,
            'asset_tag' => $asset->asset_tag,
        ];


        // This item is checked out to a location
        if (request('checkout_to_type') == 'location') {
            $target = Location::find(request('assigned_location'));
            $asset->location_id = ($target) ? $target->id : '';
            $error_payload['target_id'] = $request->input('assigned_location');
            $error_payload['target_type'] = 'location';

        } elseif (request('checkout_to_type') == 'asset') {
            $target = Asset::where('id', '!=', $asset_id)->find(request('assigned_asset'));
            // Override with the asset's location_id if it has one
            $asset->location_id = (($target) && (isset($target->location_id))) ? $target->location_id : '';
            $error_payload['target_id'] = $request->input('assigned_asset');
            $error_payload['target_type'] = 'asset';

        } elseif (request('checkout_to_type') == 'user') {
            // Fetch the target and set the asset's new location_id
            $target = User::find(request('assigned_user'));
            $asset->location_id = (($target) && (isset($target->location_id))) ? $target->location_id : '';
            $error_payload['target_id'] = $request->input('assigned_user');
            $error_payload['target_type'] = 'user';
        }

        if ($request->filled('status_id')) {
            $asset->status_id = $request->get('status_id');
        }


        if (! isset($target)) {
            return response()->json(Helper::formatStandardApiResponse('error', $error_payload, 'Checkout target for asset '.e($asset->asset_tag).' is invalid - '.$error_payload['target_type'].' does not exist.'));
        }



        $checkout_at = request('checkout_at', date('Y-m-d H:i:s'));
        $expected_checkin = request('expected_checkin', null);
        $note = request('note', null);
        // Using `->has` preserves the asset name if the name parameter was not included in request.
        $asset_name = request()->has('name') ? request('name') : $asset->name;

        // Set the location ID to the RTD location id if there is one
        // Wait, why are we doing this? This overrides the stuff we set further up, which makes no sense.
        // TODO: Follow up here. WTF. Commented out for now. 


//        if ((isset($target->rtd_location_id)) && ($asset->rtd_location_id!='')) {
//            $asset->location_id = $target->rtd_location_id;
//        }



        if ($asset->checkOut($target, Auth::user(), $checkout_at, $expected_checkin, $note, $asset_name, $asset->location_id)) {
            return response()->json(Helper::formatStandardApiResponse('success', ['asset'=> e($asset->asset_tag)], trans('admin/hardware/message.checkout.success')));
        }

        return response()->json(Helper::formatStandardApiResponse('error', ['asset'=> e($asset->asset_tag)], trans('admin/hardware/message.checkout.error')));
    }


    /**
     * Checkin an asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @since [v4.0]
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkin(Request $request, $asset_id)
    {
        $this->authorize('checkin', Asset::class);
        $asset = Asset::with('model')->findOrFail($asset_id);
        $this->authorize('checkin', $asset);


        $target = $asset->assignedTo;
        if (is_null($target)) {
            return response()->json(Helper::formatStandardApiResponse('error', [
                'asset_tag'=> e($asset->asset_tag),
                'model' => e($asset->model->name),
                'model_number' => e($asset->model->model_number)
            ], trans('admin/hardware/message.checkin.already_checked_in')));
        }

        $asset->expected_checkin = null;
        $asset->last_checkout = null;
        $asset->last_checkin = now();
        $asset->assigned_to = null;
        $asset->assignedTo()->disassociate($asset);
        $asset->accepted = null;

        if ($request->has('name')) {
            $asset->name = $request->input('name');
        }

        $asset->location_id = $asset->rtd_location_id;

        if ($request->filled('location_id')) {
            $asset->location_id = $request->input('location_id');
        }

        if ($request->has('status_id')) {
            $asset->status_id = $request->input('status_id');
        }
        
        $checkin_at = $request->filled('checkin_at') ? $request->input('checkin_at').' '. date('H:i:s') : date('Y-m-d H:i:s');
        $originalValues = $asset->getRawOriginal();

        if (($request->filled('checkin_at')) && ($request->get('checkin_at') != date('Y-m-d'))) {
            $originalValues['action_date'] = $checkin_at;
        }

        if(!empty($asset->licenseseats->all())){
            foreach ($asset->licenseseats as $seat){
                $seat->assigned_to = null;
                $seat->save();
            }
        }

        if ($asset->save()) {
            event(new CheckoutableCheckedIn($asset, $target, Auth::user(), $request->input('note'), $checkin_at, $originalValues));

            return response()->json(Helper::formatStandardApiResponse('success', [
                'asset_tag'=> e($asset->asset_tag),
                'model' => e($asset->model->name),
                'model_number' => e($asset->model->model_number)
            ], trans('admin/hardware/message.checkin.success')));
        }

        return response()->json(Helper::formatStandardApiResponse('error', ['asset'=> e($asset->asset_tag)], trans('admin/hardware/message.checkin.error')));
    }

    /**
     * Checkin an asset by asset tag
     *
     * @author [A. Janes] [<ajanes@adagiohealth.org>]
     * @since [v6.0]
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkinByTag(Request $request, $tag = null)
    {
        $this->authorize('checkin', Asset::class);
        if(null == $tag && null !== ($request->input('asset_tag'))) {
            $tag = $request->input('asset_tag');
        }
        $asset = Asset::where('asset_tag', $tag)->first();

        if ($asset) {
            return $this->checkin($request, $asset->id);
        }

        return response()->json(Helper::formatStandardApiResponse('error', [
            'asset'=> e($tag)
        ], 'Asset with tag '.e($tag).' not found'));
    }


    /**
     * Mark an asset as audited
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $id
     * @since [v4.0]
     * @return \Illuminate\Http\JsonResponse
     */
    public function audit(Request $request)

    {
        $this->authorize('audit', Asset::class);
        $rules = [
            'asset_tag' => 'required',
            'location_id' => 'exists:locations,id|nullable|numeric',
            'next_audit_date' => 'date|nullable',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $validator->errors()->all()));
        }

        $settings = Setting::getSettings();
        $dt = Carbon::now()->addMonths($settings->audit_interval)->toDateString();

        $asset = Asset::where('asset_tag', '=', $request->input('asset_tag'))->first();


        if ($asset) {
            // We don't want to log this as a normal update, so let's bypass that
            $asset->unsetEventDispatcher();
            $asset->next_audit_date = $dt;

            if ($request->filled('next_audit_date')) {
                $asset->next_audit_date = $request->input('next_audit_date');
            }

            // Check to see if they checked the box to update the physical location,
            // not just note it in the audit notes
            if ($request->input('update_location') == '1') {
                $asset->location_id = $request->input('location_id');
            }

            $asset->last_audit_date = date('Y-m-d H:i:s');

            if ($asset->save()) {
                $log = $asset->logAudit(request('note'), request('location_id'));

                return response()->json(Helper::formatStandardApiResponse('success', [
                    'asset_tag'=> e($asset->asset_tag),
                    'note'=> e($request->input('note')),
                    'next_audit_date' => Helper::getFormattedDateObject($asset->next_audit_date),
                ], trans('admin/hardware/message.audit.success')));
            }
        }

        return response()->json(Helper::formatStandardApiResponse('error', ['asset_tag'=> e($request->input('asset_tag'))], 'Asset with tag '.e($request->input('asset_tag')).' not found'));
    }



    /**
     * Returns JSON listing of all requestable assets
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return \Illuminate\Http\JsonResponse
     */
    public function requestable(Request $request)
    {
        $this->authorize('viewRequestable', Asset::class);

        $allowed_columns = [
            'name',
            'asset_tag',
            'serial',
            'model_number',
            'image',
            'purchase_cost',
            'expected_checkin',
        ];

        $all_custom_fields = CustomField::all(); //used as a 'cache' of custom fields throughout this page load

        foreach ($all_custom_fields as $field) {
            $allowed_columns[] = $field->db_column_name();
        }

        $assets = Asset::select('assets.*')
            ->with('location', 'assetstatus', 'assetlog', 'company','assignedTo',
                'model.category', 'model.manufacturer', 'model.fieldset', 'supplier', 'requests')
            ->requestableAssets();




        if ($request->filled('search')) {
            $assets->TextSearch($request->input('search'));
        }

        // Search custom fields by column name
        foreach ($all_custom_fields as $field) {
            if ($request->filled($field->db_column_name())) {
                $assets->where($field->db_column_name(), '=', $request->input($field->db_column_name()));
            }
        }

        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';
        $sort_override = str_replace('custom_fields.', '', $request->input('sort'));

        // This handles all the pivot sorting (versus the assets.* fields
        // in the allowed_columns array)
        $column_sort = in_array($sort_override, $allowed_columns) ? $sort_override : 'assets.created_at';

        switch ($request->input('sort')) {
            case 'model':
                $assets->OrderModels($order);
                break;
            case 'model_number':
                $assets->OrderModelNumber($order);
                break;
            case 'location':
                $assets->OrderLocation($order);
                break;
            default:
                $assets->orderBy($column_sort, $order);
                break;
        }


        // Make sure the offset and limit are actually integers and do not exceed system limits
        $offset = ($request->input('offset') > $assets->count()) ? $assets->count() : app('api_offset_value');
        $limit = app('api_limit_value');

        $total = $assets->count();
        $assets = $assets->skip($offset)->take($limit)->get();

        return (new AssetsTransformer)->transformRequestedAssets($assets, $total);
    }
}
