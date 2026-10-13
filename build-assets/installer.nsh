; Visual C++ 2015-2022 Redistributable x64 — kurulu değilse yükle
!macro customInstall
  ; VC++ 2022 x64 kayıt defteri anahtarını kontrol et
  ReadRegDWORD $0 HKLM "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" "Installed"
  ${If} $0 != 1
    DetailPrint "Visual C++ Redistributable kuruluyor..."
    SetOutPath "$PLUGINSDIR"
    File "${PROJECT_DIR}\build-assets\vc_redist.x64.exe"
    ExecWait '"$PLUGINSDIR\vc_redist.x64.exe" /install /quiet /norestart' $1
    ${If} $1 == 3010
      ; 3010 = kuruldu ama yeniden başlatma gerekiyor
      SetRebootFlag true
      MessageBox MB_OK|MB_ICONINFORMATION "Visual C++ kuruldu. Veresiyem'i ilk kez çalıştırmadan önce bilgisayarınızı yeniden başlatın."
    ${ElseIf} $1 != 0
      MessageBox MB_OK|MB_ICONEXCLAMATION "Visual C++ kurulumu başarısız oldu (kod: $1). Program yine de kurulmaya devam edecek."
    ${EndIf}
  ${Else}
    DetailPrint "Visual C++ Redistributable zaten kurulu, atlanıyor."
  ${EndIf}
!macroend
