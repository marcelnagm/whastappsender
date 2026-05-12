@extends('layouts.app-master')

@section('template_title', 'User management')

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-sm-12">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
                <h1 class="h3 mb-0 text-gray-800 fw-bold">
                    <i class="bi bi-shield-lock-fill text-primary me-2"></i>Access control
                </h1>
                <div class="d-flex gap-2">
                    <form method="GET" action="{{ route('users.index') }}" class="d-flex gap-1 shadow-sm rounded">
                        <input type="text" name="search" class="form-control form-control-sm border-0 px-3"
                            placeholder="Name, email or username..." value="{{ request('search') }}" style="min-width: 250px;">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search"></i>
                        </button>
                    </form>
                    
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                    <h6 class="m-0 font-weight-bold text-primary">Registered users ({{ $users->total() }})</h6>
                </div>

                @if ($message = Session::get('success'))
                <div class="alert alert-success border-0 rounded-0 m-0">
                    <i class="bi bi-check-circle-fill me-2"></i> {{ $message }}
                </div>
                @endif

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-uppercase fw-bold">
                                <tr>
                                    <th class="ps-4">ID</th>
                                    <th>User</th>
                                    <th>Email</th>
                                    <th class="text-center">Role</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($users as $user)
                                <tr>
                                    <td class="ps-4 text-muted">{{ $user->id }}</td>
                                    <td>
                                        <div class="fw-bold">{{ $user->name }}</div>
                                        <div class="small text-muted">@<span>{{ $user->username }}</span></div>
                                    </td>
                                    <td>{{ $user->email }}</td>
                                    <td class="text-center">
                                        <span class="badge {{ $user->role == 'admin' ? 'bg-danger' : 'bg-primary' }} rounded-pill px-3">
                                            {{ strtoupper($user->role) }}
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <form action="{{ route('users.toggleActive', $user->id) }}" method="POST">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="btn btn-sm border-0 bg-transparent">
                                                @if($user->active)
                                                    <i class="bi bi-toggle-on text-success fs-4"></i>
                                                @else
                                                    <i class="bi bi-toggle-off text-secondary fs-4"></i>
                                                @endif
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm shadow-sm border rounded">
                                            <a class="btn btn-white text-primary" href="{{ route('users.edit', $user->id) }}" title="Edit user">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                            
                                            <form action="{{ route('users.toggleAdmin', $user->id) }}" method="POST" style="display:inline">
                                                @csrf @method('PATCH')
                                                <button type="submit" class="btn btn-white text-orange-500" title="Toggle admin / user">
                                                    <i class="bi bi-person-badge"></i>
                                                </button>
                                            </form>

                                            <form action="{{ route('users.destroy', $user->id) }}" method="POST" style="display:inline">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="btn btn-white text-danger" 
                                                    onclick="return confirm('WARNING: Deleting this user will revoke their access to the system. Continue?')">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        No users found.
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="py-4 d-flex justify-content-center border-top bg-light">
                    {{ $users->appends(request()->query())->links('pagination::bootstrap-4') }}
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Keep visual identity for toolbar buttons */
    .btn-white { background: white; border: none; }
    .btn-white:hover { background: #f8f9fa; }
    .text-orange-500 { color: #f97316; }
    
    /* Pagination tweaks */
    nav[role="navigation"] svg { width: 20px; height: 20px; }
</style>
@endsection