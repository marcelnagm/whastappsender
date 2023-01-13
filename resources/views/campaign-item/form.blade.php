<div class="box box-info padding-1">
    <div class="box-body">
        
        <div class="form-group">
            {{ Form::label('name') }}
            {{ Form::text('name', $campaignItem->name, ['class' => 'form-control' . ($errors->has('name') ? ' is-invalid' : ''), 'placeholder' => 'Name']) }}
            {!! $errors->first('name', '<div class="invalid-feedback">:message</div>') !!}
        </div>
        <div class="form-group">
            {{ Form::label('text') }}
            {{ Form::hidden('text', $campaignItem->text, ['class' => 'form-control' . ($errors->has('text') ? ' is-invalid' : ''),'id' => 'message', 'placeholder' => 'Text']) }}
            <div id="whatsapp-editor-container">
                
</div>
            {!! $errors->first('text', '<div class="invalid-feedback">:message</div>') !!}
        </div>
        <div class="form-group">
            {{ Form::label('user_id') }}
            {{ Form::text('user_id', $campaignItem->user_id, ['class' => 'form-control' . ($errors->has('user_id') ? ' is-invalid' : ''), 'placeholder' => 'User Id']) }}
            {!! $errors->first('user_id', '<div class="invalid-feedback">:message</div>') !!}
        </div>
        <div class="form-group">
            {{ Form::label('campaign_id') }}
            {{ Form::text('campaign_id', $campaignItem->campaign_id, ['class' => 'form-control' . ($errors->has('campaign_id') ? ' is-invalid' : ''), 'placeholder' => 'Campaign Id']) }}
            {!! $errors->first('campaign_id', '<div class="invalid-feedback">:message</div>') !!}
        </div>

    </div>
    <div class="box-footer mt20">
        <button onclick="sendForm()" type="submit" class="btn btn-primary">Submit</button>
    </div>
</div>


@section('js')
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<script src="{{ url('assets/js/whatsapp-editor.js') }}"></script>
<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.6.3/css/all.css" integrity="sha384-UHRtZLI+pbxtHCWp1t77Bi1L4ZtiqrqD80Kn4Z8NTSRyMA2Fd33n5dQ8lWUE00s/" crossorigin="anonymous">

<link href="{{url('assets/css/whatsapp-editor.css') }}" rel="stylesheet">
<script>

   function  format_text(text){
        return text.replace(/(?:\*)(?:(?!\s))((?:(?!\*|\n).)+)(?:\*)/g,'<b>$1</b>')
           .replace(/(?:_)(?:(?!\s))((?:(?!\n|_).)+)(?:_)/g,'<i>$1</i>')
           .replace(/(?:~)(?:(?!\s))((?:(?!\n|~).)+)(?:~)/g,'<s>$1</s>')
           .replace(/(?:--)(?:(?!\s))((?:(?!\n|--).)+)(?:--)/g,'<u>$1</u>')
           .replace(/(?:```)(?:(?!\s))((?:(?!\n|```).)+)(?:```)/g,'<tt>$1</tt>')
           .replace(/(?:\r\n|\r|\n)/g, '<div></div>');
        
      
        }
function sendForm(){
    alert('foi');
    
    $('#message').val(editor.getFormattedContent());
    $('form#myForm').submit();
}

         var editor;
        
        $(function () {
            editor = $("#whatsapp-editor-container").whatsappEditor({content: format_text("{{$campaignItem->text()}}
            ")});
        });

        function getWhatAppFormattedContent() {
            alert(editor.getFormattedContent());
            return false;
        }
</script>

@endsection