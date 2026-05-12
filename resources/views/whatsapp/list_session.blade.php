
<thead class="thead-light">
    <tr>
        <th class="" scope="col">Session</th>
        <th class="" scope="col">Hardware</th>
        <th class="" scope="col">Battery</th>
        <th class="" scope="col">Connection</th>
        <th class="" scope="col">Actions</th>
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
