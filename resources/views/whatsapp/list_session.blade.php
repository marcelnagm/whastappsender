
<thead class="thead-light">
    <tr>
        <th class="" scope="col">{{ __('Session') }}</th>
        <th class="" scope="col">{{ __('Hardware') }}</th>
        <th class="" scope="col">{{ __('Battery') }}</th>
        <th class="" scope="col">{{ __('Connection') }}</th>
        <th class="" scope="col">{{ __('Actions') }}</th>
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
