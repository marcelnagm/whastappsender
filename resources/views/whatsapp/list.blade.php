
<thead class="thead-light">
    <tr>
        <th class="table-web" scope="col">When the action runs</th>
        <th class="table-web" scope="col">Message sent</th>
    </tr>
</thead>
<tbody>
    @if(count($items)>0)    
    @foreach($items as $item)
    <tr>    
        <td class="table-web" id="edit_d_{{$item->id}}" >
            {{ $item->getParameter()->name }} 
        </td>
        <td class="table-web" id="edit_c_{{$item->id}}">
         {!! $item->message !!}
        </td>
        <td class="table-web">
            <a type="button" class="btn btn-danger" value="x" href="{{ route('whatsapp.delete',$item->id) }}">Remove</a>
            </br>
            <a type="button" class="btn btn-primary" value="x" href="{{ route('whatsapp.edit',$item->id) }}">Edit</a>
        </td>
    </tr>
    @endforeach
    @else
    <tr>
        <td colspan='3'>No custom messages</td>
    </tr>
    @endif
</tbody>
