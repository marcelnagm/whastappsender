            @csrf
        @method('post')        
         @include('partials.fields',['fields'=>[
            ['ftype'=>'select','name'=>"Type",'id'=>"tipo",'required'=>true,'data'=> $data,'value'=> isset($object) ? $object->parameter:'','edit'=> isset($object) ? true:false],
        ]])        
         @include('partials.fields',['fields'=>[
            ['ftype'=>'textarea','name'=>"Message",'id'=>"mensagem",'placeholder'=>"Enter your custom message",'required'=>true,'value'=> isset($object) ? $object->message:''],       
        ]])        
        <a class="btn btn-primary " href="{{ route('whatsapp.index') }}" style="margin-top: 25px;">Back to list</a>  
        <button type='submit' class="btn btn-success mt-4">{{ __('Save') }}</button>        
        
