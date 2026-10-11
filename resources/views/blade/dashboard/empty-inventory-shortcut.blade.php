<div class="row">
    <div class="col-md-12">
        <div class="box box-default skynet-empty-inventory">
            <div class="box-header with-border">
                <h2 class="box-title">{{ trans('general.new_asset') }}</h2>
            </div>
            <div class="box-body">
                <div class="row">
                    <div class="col-md-12">
                        <p><strong>{{ trans('general.dashboard_empty') }}</strong></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-2">
                        @can('create', \App\Models\Asset::class)
                            <a class="btn bg-teal" style="width: 100%" href="{{ route('hardware.create') }}">{{ trans('general.new_asset') }}</a>
                        @endcan
                    </div>
                    <div class="col-md-2">
                        @can('create', \App\Models\License::class)
                            <a class="btn bg-maroon" style="width: 100%" href="{{ route('licenses.create') }}">{{ trans('general.new_license') }}</a>
                        @endcan
                    </div>
                    <div class="col-md-2">
                        @can('create', \App\Models\Accessory::class)
                            <a class="btn bg-orange" style="width: 100%" href="{{ route('accessories.create') }}">{{ trans('general.new_accessory') }}</a>
                        @endcan
                    </div>
                    <div class="col-md-2">
                        @can('create', \App\Models\Consumable::class)
                            <a class="btn bg-purple" style="width: 100%" href="{{ route('consumables.create') }}">{{ trans('general.new_consumable') }}</a>
                        @endcan
                    </div>
                    <div class="col-md-2">
                        @can('create', \App\Models\Component::class)
                            <a class="btn bg-yellow" style="width: 100%" href="{{ route('components.create') }}">{{ trans('general.new_component') }}</a>
                        @endcan
                    </div>
                    <div class="col-md-2">
                        @can('create', \App\Models\User::class)
                            <a class="btn bg-light-blue" style="width: 100%" href="{{ route('users.create') }}">{{ trans('general.new_user') }}</a>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
