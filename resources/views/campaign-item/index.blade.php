@extends('layouts.app-master')

@section('template_title')
    Campaign Item
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-sm-12">
                <div class="card">
                    <div class="card-header">
                        <div style="display: flex; justify-content: space-between; align-items: center;">

                            <span id="card_title">
                                {{ __('Campaign Item') }}
                            </span>

                             <div class="float-right">
                                <a href="{{ route('campaign-items.create') }}" class="btn btn-primary btn-sm float-right"  data-placement="left">
                                  {{ __('Create New') }}
                                </a>
                              </div>
                        </div>
                    </div>
                    @if ($message = Session::get('success'))
                        <div class="alert alert-success">
                            <p>{{ $message }}</p>
                        </div>
                    @endif

                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="thead">
                                    <tr>
                                        <th>No</th>
                                        
										<th>Name</th>
										<th>Text</th>
										<th>User Id</th>
										<th>Campaign Id</th>

                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($campaignItems as $campaignItem)
                                        <tr>
                                            <td>{{ ++$i }}</td>
                                            
											<td>{{ $campaignItem->name }}</td>
											<td>{{ $campaignItem->text }}</td>
											<td>{{ $campaignItem->user_id }}</td>
											<td>{{ $campaignItem->campaign_id }}</td>

                                            <td>
                                                <form action="{{ route('campaign-items.destroy',$campaignItem->id) }}" method="POST">
                                                    <a class="btn btn-sm btn-primary " href="{{ route('campaign-items.show',$campaignItem->id) }}"><i class="fa fa-fw fa-eye"></i> Show</a>
                                                    <a class="btn btn-sm btn-success" href="{{ route('campaign-items.edit',$campaignItem->id) }}"><i class="fa fa-fw fa-edit"></i> Edit</a>
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fa fa-fw fa-trash"></i> Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                {!! $campaignItems->links() !!}
            </div>
        </div>
    </div>
@endsection
