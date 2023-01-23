
<thead class="thead-light">
    <tr>
        <th class="" scope="col">Sessão </th>
        <th class="" scope="col">Hardware </th>
        <th class="" scope="col">Estado da Bateria</th>
        <th class="" scope="col">Status de Conexão</th>
        <th class="" scope="col">Ações</th>
    </tr>
</thead>
<tbody>    
    <tr>    
        <td class="" >
            {{$device['session']}}
        </td>
        <td class="" >
            <!--{-{$device['hw']}}-->
        </td>
        <td class="" >
            <!--{-{$device['batt']}}-->
        </td>
        <td class="" >
            <!--{-{$device['respond']}}-->
        </td>
        <td class="table-web">
            <a type="button" href="{{ route('whatsapp.delete') }}" class="btn btn-danger" value="x" onclick="">X</a>            
        </td>
    </tr>
    
</tbody>
