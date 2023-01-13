            @csrf
        @method('post')        
         @include('partials.fields',['fields'=>[
            ['ftype'=>'select','name'=>"Tipo",'id'=>"tipo",'required'=>true,'data'=> $data,'value'=> isset($object) ? $object->parameter:'','edit'=> isset($object) ? true:false],
        ]])        
         @include('partials.fields',['fields'=>[
            ['ftype'=>'textarea','name'=>"Mensagem",'id'=>"mensagem",'placeholder'=>"Coloque a sua mensagem personalizada",'required'=>true,'value'=> isset($object) ? $object->message:''],       
        ]])        
        <a class="btn btn-primary " href="{{ route('whatsapp.index') }}" style="margin-top: 25px;">Retornar a Lista</a>  
        <button type='submit' class="btn btn-success mt-4">{{ __('Save') }}</button>        
        
