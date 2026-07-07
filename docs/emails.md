# Emails del proyecto

Este documento enumera los emails que el proyecto gestiona actualmente en el código.

## Correo repetido en Mailpit

El email que aparece en la captura, con asunto `Nueva medida personalizada en —` y remitente `bi-reply@begreenmyfriend.com`, corresponde a la notificación de alta de medida personalizada del plan de sostenibilidad.

Se envía cuando se añade una medida personalizada en la review del plan. Si lo ves varias veces seguidas, normalmente significa que se están creando varias medidas personalizadas desde esa pantalla o que el flujo se está disparando repetidamente durante pruebas/manuales.

## Flujos de email activos

| Flujo | Disparador | Remitente | Destinatario | Plantilla / asunto |
| --- | --- | --- | --- | --- |
| Verificación de registro | Alta de usuario en `/register/{_locale}` | `noreply@begreenmyfriend.com` | Email del usuario registrado | `registration/confirmation_email.html.twig`, asunto `registration.email.subject` |
| Recuperación de contraseña | Solicitud de reset de contraseña | `noreply@begreenmyfriend.com` | Email del usuario que pidió el reset | Asunto `reset_password.email_subject`, cuerpo `reset_password.email_message` |
| Contacto público | Envío del formulario de contacto de la home | Email introducido por el usuario | `CONTACT_EMAIL` | `emails/contact.html.twig`, asunto fijo `Nuevo mensaje de contacto desde begreenmyfriend.com` |
| Notificación de medida personalizada | Alta de una medida personalizada en el plan | `app.mail_from` | `CONTACT_EMAIL` | Texto localizado vía `backend.plan.custom_measures.notification.subject` y `backend.plan.custom_measures.notification.body` |
| Envío ET desde review | Acción `send_et_emails` en review del plan | `app.mail_from` | Miembros del crew seleccionados | PDF adjunto generado al vuelo, asunto `backend.plan.email.subject` |

## Flujo interno / diagnóstico

| Flujo | Uso | Remitente | Destinatario |
| --- | --- | --- | --- |
| Email de prueba | Comando `app:send-test-email` | Gmail configurado en el comando | Email pasado por argumento, o `federicomartin2004@gmail.com` por defecto |

## Archivos implicados

- [app/src/Controller/RegistrationController.php](/home/fede/www/begreenmyfriend/app/src/Controller/RegistrationController.php)
- [app/src/Security/ResetPasswordHelper.php](/home/fede/www/begreenmyfriend/app/src/Security/ResetPasswordHelper.php)
- [app/src/Controller/HomeController.php](/home/fede/www/begreenmyfriend/app/src/Controller/HomeController.php)
- [app/src/Service/SustainabilityPlanCustomMeasureService.php](/home/fede/www/begreenmyfriend/app/src/Service/SustainabilityPlanCustomMeasureService.php)
- [app/src/Controller/Backend/PlanController.php](/home/fede/www/begreenmyfriend/app/src/Controller/Backend/PlanController.php)
- [app/src/Command/SendTestEmailCommand.php](/home/fede/www/begreenmyfriend/app/src/Command/SendTestEmailCommand.php)
