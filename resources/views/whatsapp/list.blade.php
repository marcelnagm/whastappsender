
<thead class="thead-light">
    <tr>
        <th class="table-web" scope="col">Quando Ocorre a ação</th>
        <th class="table-web" scope="col">Mensagem Enviada</th>
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
            <a type="button" class="btn btn-danger" value="x" href="{{ route('whatsapp.delete',$item->id) }}">Remover</a>
            </br>
            <a type="button" class="btn btn-primary" value="x" href="{{ route('whatsapp.edit',$item->id) }}">Editar</a>
        </td>
    </tr>
    @endforeach
    @else
    <tr>
        <td colspan='3'>Nenhuma Mensagem Personalizada</td>
    </tr>
    @endif
</tbody>
